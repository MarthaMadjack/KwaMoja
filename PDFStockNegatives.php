<?php

include('includes/session.inc');

include('includes/PDFStarter.php');
$pdf->addInfo('Title', _('Inventory Negatives Listing'));
$pdf->addInfo('Subject', _('Inventory Negatives Listing'));
$FontSize = 9;
$PageNumber = 1;
$line_height = 15;

$Title = _('Negative Stock Listing Error');
$ErrMsg = _('An error occurred retrieving the negative quantities.');
$DbgMsg = _('The sql that failed to retrieve the negative quantities was');

if ($_SESSION['RestrictLocations'] == 0) {
	$sql = "SELECT stockmaster.stockid,
					stockmaster.description,
					stockmaster.categoryid,
					stockmaster.decimalplaces,
					locstock.loccode,
					locations.locationname,
					locstock.quantity
				FROM stockmaster
				INNER JOIN locstock
					ON stockmaster.stockid=locstock.stockid
				INNER JOIN locations
					ON locstock.loccode = locations.loccode
				WHERE locstock.quantity < 0
				ORDER BY locstock.loccode,
						stockmaster.categoryid,
						stockmaster.stockid,
						stockmaster.decimalplaces";
} else {
	$sql = "SELECT stockmaster.stockid,
					stockmaster.description,
					stockmaster.categoryid,
					stockmaster.decimalplaces,
					locstock.loccode,
					locations.locationname,
					locstock.quantity
				FROM stockmaster
				INNER JOIN locstock
					ON stockmaster.stockid=locstock.stockid
				INNER JOIN locations
					ON locstock.loccode = locations.loccode
				INNER JOIN www_users
					ON locations.loccode=www_users.defaultlocation
				WHERE locstock.quantity < 0
					AND www_users.userid='" . $_SESSION['UserID'] . "'
				ORDER BY locstock.loccode,
						stockmaster.categoryid,
						stockmaster.stockid,
						stockmaster.decimalplaces";
}

$result = DB_query($sql, $db, $ErrMsg, $DbgMsg);

if (DB_num_rows($result) == 0) {
	include('includes/header.inc');
	prnMsg(_('There are no negative stocks to list'), 'error');
	include('includes/footer.inc');
	exit;
}

$NegativesRow = DB_fetch_array($result);

include('includes/PDFStockNegativesHeader.inc');
$line_height = 15;
$FontSize = 10;

do {

	$LeftOvers = $pdf->addTextWrap($Left_Margin, $YPos, 130, $FontSize, $NegativesRow['loccode'] . ' - ' . $NegativesRow['locationname'], 'left');
	$LeftOvers = $pdf->addTextWrap(170, $YPos, 350, $FontSize, $NegativesRow['stockid'] . ' - ' . $NegativesRow['description'], 'left');
	$LeftOvers = $pdf->addTextWrap(520, $YPos, 30, $FontSize, locale_number_format($NegativesRow['quantity'], $NegativesRow['decimalplaces']), 'right');

	$pdf->line($Left_Margin, $YPos - 2, $Page_Width - $Right_Margin, $YPos - 2);

	$YPos -= $line_height;

	if ($YPos < $Bottom_Margin + $line_height) {
		$PageNumber++;
		include('includes/PDFStockNegativesHeader.inc');
	}

} while ($NegativesRow = DB_fetch_array($result));

if (DB_num_rows($result) > 0) {
	$pdf->OutputD($_SESSION['DatabaseName'] . '_NegativeStocks_' . date('Y-m-d') . '.pdf');
	$pdf->__destruct();
} else {
	$Title = _('Negative Stock Listing Problem');
	include('includes/header.inc');
	prnMsg(_('There are no negative stocks to list'), 'info');
	include('includes/footer.inc');
}
?>