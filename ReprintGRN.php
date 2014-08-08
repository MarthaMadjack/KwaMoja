<?php

include('includes/session.inc');
$Title = _('Reprint a GRN');
include('includes/header.inc');

echo '<p class="page_title_text noPrint" ><img src="' . $RootPath . '/css/' . $Theme . '/images/supplier.png" title="' . $Title . '" alt="" />' . ' ' . $Title . '</p>';

if (!isset($_POST['PONumber'])) {
	$_POST['PONumber'] = '';
}

echo '<form onSubmit="return VerifyForm(this);" action="' . htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8') . '" method="post" class="noPrint">';
echo '<input type="hidden" name="FormID" value="' . $_SESSION['FormID'] . '" />';
echo '<table class="selection">
		<tr>
			<th colspan="2"><h3>' . _('Select a purchase order') . '</h3></th>
		</tr>
		<tr>
			<td>' . _('Enter a Purchase Order Number') . '</td>
			<td>' . '<input type="text" name="PONumber" required="required" minlength="1" maxlength="7" class="number" size="7" value="' . $_POST['PONumber'] . '" /></td>
		</tr>
		<tr>
			<td colspan="2" style="text-align: center"><input type="submit" name="Show" value="' . _('Show GRNs') . '" /></td>
		</tr>
	</table>
	</form>';

if (isset($_POST['Show'])) {
	if ($_POST['PONumber'] == '') {
		echo '<br />';
		prnMsg(_('You must enter a purchase order number in the box above'), 'warn');
		include('includes/footer.inc');
		exit;
	}
	$SQL = "SELECT count(orderno)
				FROM purchorders
				WHERE orderno='" . $_POST['PONumber'] . "'";
	$Result = DB_query($SQL);
	$MyRow = DB_fetch_row($Result);
	if ($MyRow[0] == 0) {
		echo '<br />';
		prnMsg(_('This purchase order does not exist on the system. Please try again.'), 'warn');
		include('includes/footer.inc');
		exit;
	}
	$SQL = "SELECT grnbatch,
				grnno,
				grns.podetailitem,
				grns.itemcode,
				grns.itemdescription,
				grns.deliverydate,
				grns.qtyrecd,
				suppliers.suppname,
				stockmaster.decimalplaces
			FROM grns
			INNER JOIN suppliers
				ON grns.supplierid=suppliers.supplierid
			INNER JOIN purchorderdetails
				ON grns.podetailitem=purchorderdetails.podetailitem
			INNER JOIN purchorders
				ON purchorders.orderno=purchorderdetails.orderno
			LEFT JOIN stockmaster
				ON grns.itemcode=stockmaster.stockid
			INNER JOIN locationusers
				ON locationusers.loccode=purchorders.intostocklocation
				AND locationusers.userid='" .  $_SESSION['UserID'] . "'
				AND locationusers.canview=1
			WHERE purchorderdetails.orderno='" . $_POST['PONumber'] ."'";
	$Result = DB_query($SQL);
	if (DB_num_rows($Result) == 0) {
		echo '<br />';
		prnMsg(_('There are no GRNs for this purchase order that can be reprinted.'), 'warn');
		include('includes/footer.inc');
		exit;
	}
	$k = 0;
	echo '<table class="selection">
			<tr>
				<th colspan="8"><h3>' . _('GRNs for Purchase Order No') . ' ' . $_POST['PONumber'] . '</h3></th>
			</tr>
			<tr>
				<th>' . _('Supplier') . '</th>
				<th>' . _('PO Order line') . '</th>
				<th>' . _('GRN Number') . '</th>
				<th>' . _('Item Code') . '</th>
				<th>' . _('Item Description') . '</th>
				<th>' . _('Delivery Date') . '</th>
				<th>' . _('Quantity Received') . '</th>
			</tr>';

	while ($MyRow = DB_fetch_array($Result)) {
		if ($k == 1) {
			echo '<tr class="EvenTableRows">';
			$k = 0;
		} else {
			echo '<tr class="OddTableRows">';
			$k = 1;
		}
		echo '<td>' . $MyRow['suppname'] . '</td>
			<td class="number">' . $MyRow['podetailitem'] . '</td>
			<td class="number">' . $MyRow['grnbatch'] . '</td>
			<td>' . $MyRow['itemcode'] . '</td>
			<td>' . $MyRow['itemdescription'] . '</td>
			<td>' . $MyRow['deliverydate'] . '</td>
			<td class="number">' . locale_number_format($MyRow['qtyrecd'], $MyRow['decimalplaces']) . '</td>
			<td><a href="PDFGrn.php?GRNNo=' . urlencode($MyRow['grnbatch']) . '&PONo=' . urlencode($_POST['PONumber']) . '">' . _('Reprint GRN ') . '</a></td>
			<td><a href="PDFQALabel.php?GRNNo=' . urlencode($MyRow['grnbatch']) .'&PONo=' . urlencode($_POST['PONumber']) . '">' . _('Reprint QA Label') . '</a></td>
		</tr>';
	}
	echo '</table>';
}

include('includes/footer.inc');

?>