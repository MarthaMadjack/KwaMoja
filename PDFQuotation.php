<?php

/*	Please note that addTextWrap prints a font-size-height further down than
	addText and other functions.*/

include('includes/session.inc');
include('includes/SQL_CommonFunctions.inc');

//Get Out if we have no order number to work with
if (!isset($_GET['QuotationNo']) or $_GET['QuotationNo'] == "") {
	$Title = _('Select Quotation To Print');
	include('includes/header.inc');
	echo '<div class="centre">';
	prnMsg(_('Select a Quotation to Print before calling this page'), 'error');
	echo '<table class="table_index">
				<tr>
					<td class="menu_group_item">
						<a href="' . $RootPath . '/SelectSalesOrder.php?Quotations=Quotes_Only">' . _('Quotations') . '</a></td>
				</tr>
			</table>
			</div>';
	include('includes/footer.inc');
	exit();
}

/*retrieve the order details from the database to print */
$ErrMsg = _('There was a problem retrieving the quotation header details for Order Number') . ' ' . $_GET['QuotationNo'] . ' ' . _('from the database');

$SQL = "SELECT salesorders.customerref,
				salesorders.comments,
				salesorders.orddate,
				salesorders.deliverto,
				salesorders.deladd1,
				salesorders.deladd2,
				salesorders.deladd3,
				salesorders.deladd4,
				salesorders.deladd5,
				salesorders.deladd6,
				debtorsmaster.name,
				debtorsmaster.currcode,
				debtorsmaster.address1,
				debtorsmaster.address2,
				debtorsmaster.address3,
				debtorsmaster.address4,
				debtorsmaster.address5,
				debtorsmaster.address6,
				shippers.shippername,
				salesorders.printedpackingslip,
				salesorders.datepackingslipprinted,
				salesorders.quotedate,
				salesorders.branchcode,
				locations.taxprovinceid,
				locations.locationname,
				currencies.currency,
				currencies.decimalplaces AS currdecimalplaces
			FROM salesorders INNER JOIN debtorsmaster
			ON salesorders.debtorno=debtorsmaster.debtorno
			INNER JOIN shippers
			ON salesorders.shipvia=shippers.shipper_id
			INNER JOIN locations
			ON salesorders.fromstkloc=locations.loccode
			INNER JOIN currencies
			ON debtorsmaster.currcode=currencies.currabrev
			WHERE salesorders.quotation=1
			AND salesorders.orderno='" . $_GET['QuotationNo'] . "'";

$Result = DB_query($SQL, $ErrMsg);

//if there are no rows, there's a problem.
if (DB_num_rows($Result) == 0) {
	$Title = _('Print Quotation Error');
	include('includes/header.inc');
	echo '<div class="centre">';
	prnMsg(_('Unable to Locate Quotation Number') . ' : ' . $_GET['QuotationNo'] . ' ', 'error');
	echo '<table class="table_index">
				<tr>
					<td class="menu_group_item">
						<a href="' . $RootPath . '/SelectSalesOrder.php?Quotations=Quotes_Only">' . _('Outstanding Quotations') . '</a>
					</td>
				</tr>
				</table>
				</div>';
	include('includes/footer.inc');
	exit;
} elseif (DB_num_rows($Result) == 1) {
	/*There is only one order header returned - thats good! */

	$MyRow = DB_fetch_array($Result);
}

/*retrieve the order details from the database to print */

/* Then there's an order to print and its not been printed already (or its been flagged for reprinting/ge_Width=807;
)
LETS GO */
$PaperSize = 'A4_Landscape';// PDFStarter.php: $Page_Width=842; $Page_Height=595; $Top_Margin=30; $Bottom_Margin=30; $Left_Margin=40; $Right_Margin=30;
include('includes/PDFStarter.php');
$PDF->addInfo('Title', _('Customer Quotation'));
$PDF->addInfo('Subject', _('Quotation') . ' ' . $_GET['QuotationNo']);
$FontSize = 12;
$line_height = 12;


/* Now ... Has the order got any line items still outstanding to be invoiced */

$ErrMsg = _('There was a problem retrieving the quotation line details for quotation Number') . ' ' . $_GET['QuotationNo'] . ' ' . _('from the database');

$SQL = "SELECT salesorderdetails.stkcode,
		stockmaster.description,
		salesorderdetails.quantity,
		salesorderdetails.qtyinvoiced,
		salesorderdetails.unitprice,
		salesorderdetails.discountpercent,
		stockmaster.taxcatid,
		salesorderdetails.narrative,
		stockmaster.decimalplaces
	FROM salesorderdetails INNER JOIN stockmaster
		ON salesorderdetails.stkcode=stockmaster.stockid
	WHERE salesorderdetails.orderno='" . $_GET['QuotationNo'] . "'";

$Result = DB_query($SQL, $ErrMsg);

$ListCount = 0;

if (DB_num_rows($Result) > 0) {
	/*Yes there are line items to start the ball rolling with a page header */
	include('includes/PDFQuotationPageHeader.inc');

	$QuotationTotal = 0;
	$QuotationTotalEx = 0;
	$TaxTotal = 0;

	while ($MyRow2 = DB_fetch_array($Result)) {

		$ListCount++;
		$YPos -= $line_height;// Increment a line down for the next line item.

		if ((mb_strlen($MyRow2['narrative']) > 200 and $YPos - $line_height <= 75) or (mb_strlen($MyRow2['narrative']) > 1 and $YPos - $line_height <= 62) or $YPos - $line_height <= 50) {
			/* We reached the end of the page so finsih off the page and start a newy */
			include('includes/PDFQuotationPageHeader.inc');

		} //end if need a new page headed up

		$DisplayQty = locale_number_format($MyRow2['quantity'], $MyRow2['decimalplaces']);
		$DisplayPrevDel = locale_number_format($MyRow2['qtyinvoiced'], $MyRow2['decimalplaces']);
		$DisplayPrice = locale_number_format($MyRow2['unitprice'], $MyRow['currdecimalplaces']);
		$DisplayDiscount = locale_number_format($MyRow2['discountpercent'] * 100, 2) . '%';
		$SubTot = $MyRow2['unitprice'] * $MyRow2['quantity'] * (1 - $MyRow2['discountpercent']);
		$TaxProv = $MyRow['taxprovinceid'];
		$TaxCat = $MyRow2['taxcatid'];
		$Branch = $MyRow['branchcode'];
		$SQL3 = "SELECT taxgrouptaxes.taxauthid
					FROM taxgrouptaxes INNER JOIN custbranch
					ON taxgrouptaxes.taxgroupid=custbranch.taxgroupid
					WHERE custbranch.branchcode='" . $Branch . "'";
		$Result3 = DB_query($SQL3, $ErrMsg);
		while ($MyRow3 = DB_fetch_array($Result3)) {
			$TaxAuth = $MyRow3['taxauthid'];
		}

		$SQL4 = "SELECT * FROM taxauthrates
					WHERE dispatchtaxprovince='" . $TaxProv . "'
					AND taxcatid='" . $TaxCat . "'
					AND taxauthority='" . $TaxAuth . "'";
		$Result4 = DB_query($SQL4, $ErrMsg);
		while ($MyRow4 = DB_fetch_array($Result4)) {
			$TaxClass = 100 * $MyRow4['taxrate'];
		}

		$DisplayTaxClass = $TaxClass . '%';
		$TaxAmount = (($SubTot / 100) * (100 + $TaxClass)) - $SubTot;
		$DisplayTaxAmount = locale_number_format($TaxAmount, $MyRow['currdecimalplaces']);

		$LineTotal = $SubTot + $TaxAmount;
		$DisplayTotal = locale_number_format($LineTotal, $MyRow['currdecimalplaces']);

		$FontSize = 10;// Font size for the line item.

		$LeftOvers = $PDF->addText($Left_Margin, $YPos + $FontSize, $FontSize, $MyRow2['stkcode']);
		$LeftOvers = $PDF->addText(145, $YPos + $FontSize, $FontSize, $MyRow2['description']);
		$LeftOvers = $PDF->addTextWrap(420, $YPos, 85, $FontSize, $DisplayQty, 'right');
		$LeftOvers = $PDF->addTextWrap(485, $YPos, 85, $FontSize, $DisplayPrice, 'right');
		if ($DisplayDiscount > 0) {
			$LeftOvers = $PDF->addTextWrap(535, $YPos, 85, $FontSize, $DisplayDiscount, 'right');
		}
		$LeftOvers = $PDF->addTextWrap(585, $YPos, 85, $FontSize, $DisplayTaxClass, 'right');
		$LeftOvers = $PDF->addTextWrap(650, $YPos, 85, $FontSize, $DisplayTaxAmount, 'right');
		$LeftOvers = $PDF->addTextWrap($Page_Width - $Right_Margin - 90, $YPos, 90, $FontSize, $DisplayTotal, 'right');

		// Prints salesorderdetails.narrative:
		$FontSize2 = $FontSize * 0.8;// Font size to print salesorderdetails.narrative.
		$Width2 = $Page_Width - $Right_Margin - 145;// Width to print salesorderdetails.narrative.
		$LeftOvers = trim($MyRow2['narrative']);
		//**********
		$LeftOvers = str_replace('\n', ' ', $LeftOvers);// Replaces line feed character.
		$LeftOvers = str_replace('\r', '', $LeftOvers);// Delete carriage return character
		$LeftOvers = str_replace('\t', '', $LeftOvers);// Delete tabulator character
		//**********
		while (mb_strlen($LeftOvers) > 1) {
			$YPos -= $FontSize2;
			if ($YPos < ($Bottom_Margin)) {// Begins new page.
				include('includes/PDFQuotationPageHeader.inc');
			}
			$LeftOvers = $pdf->addTextWrap(145, $YPos, $Width2, $FontSize2, $LeftOvers);
		}

		$QuotationTotal += $LineTotal;
		$QuotationTotalEx += $SubTot;
		$TaxTotal += $TaxAmount;

	}// Ends while there are line items to print out.

	if ((mb_strlen($MyRow['comments']) > 200 and $YPos - $line_height <= 75) or (mb_strlen($MyRow['comments']) > 1 and $YPos - $line_height <= 62) or $YPos - $line_height <= 50) {
		/* We reached the end of the page so finish off the page and start a newy */
		include('includes/PDFQuotationPageHeader.inc');
	} //end if need a new page headed up

	$FontSize = 10;
	$YPos -= $line_height;
	$LeftOvers = $PDF->addTextWrap($Page_Width - $Right_Margin - 90 - 655, $YPos, 655, $FontSize, _('Quotation Excluding Tax'),'right');
	$LeftOvers = $pdf->addTextWrap($Page_Width - $Right_Margin - 90, $YPos, 90, $FontSize, locale_number_format($QuotationTotalEx,$myrow['currdecimalplaces']), 'right');
	$YPos -= $FontSize;
	$LeftOvers = $PDF->addTextWrap($Page_Width - $Right_Margin - 90 - 655, $YPos, 655, $FontSize, _('Total Tax'), 'right');
	$LeftOvers = $PDF->addTextWrap($Page_Width - $Right_Margin - 90, $YPos, 90, $FontSize, locale_number_format($TaxTotal,$MyRow['currdecimalplaces']), 'right');
	$YPos -= $FontSize;
	$LeftOvers = $PDF->addTextWrap($Page_Width - $Right_Margin - 90 - 655, $YPos, 655, $FontSize, _('Quotation Including Tax'),'right');
	$LeftOvers = $PDF->addTextWrap($Page_Width - $Right_Margin - 90, $YPos, 90, $FontSize, locale_number_format($QuotationTotal,$MyRow['currdecimalplaces']), 'right');

	// Print salesorders.comments:
	$YPos -= $FontSize * 2;
	$PDF->addText($XPos, $YPos + $FontSize, $FontSize, _('Notes').':');
	$Width2 = $Page_Width - $Right_Margin - 120;// Width to print salesorders.comments.
	$LeftOvers = trim($myrow['comments']);
	//**********
	$LeftOvers = str_replace('\n', ' ', $LeftOvers);// Replaces line feed character.
	$LeftOvers = str_replace('\r', '', $LeftOvers);// Delete carriage return character
	$LeftOvers = str_replace('\t', '', $LeftOvers);// Delete tabulator character
	//**********
	while(mb_strlen($LeftOvers) > 1) {
		$YPos -= $FontSize;
		if ($YPos < ($Bottom_Margin)) {// Begins new page.
			include('includes/PDFQuotationPageHeader.inc');
		}
		$LeftOvers = $pdf->addTextWrap(40, $YPos, $Width2, $FontSize, $LeftOvers);
	}
}
/*end if there are line details to show on the quotation*/


if ($ListCount == 0) {
	$Title = _('Print Quotation Error');
	include('includes/header.inc');
	prnMsg(_('There were no items on the quotation') . '. ' . _('The quotation cannot be printed'), 'info');
	echo '<br /><a href="' . $RootPath . '/SelectSalesOrder.php?Quotation=Quotes_only">' . _('Print Another Quotation') . '</a>
			<br /><a href="' . $RootPath . '/index.php">' . _('Back to the menu') . '</a>';
	include('includes/footer.inc');
	exit;
} else {
    $PDF->OutputI($_SESSION['DatabaseName'] . '_Quotation_' . $_GET['QuotationNo'] . '_' . date('Y-m-d') . '.pdf');
	$PDF->__destruct();
}
?>