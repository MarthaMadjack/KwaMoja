<?php

include('includes/DefinePOClass.php');
include('includes/SQL_CommonFunctions.inc');

/* Session started in header.inc for password checking
 * and authorisation level check
 */
include('includes/session.inc');
$Title = _('Purchase Order Items');

$identifier = $_GET['identifier'];

/* If a purchase order header doesn't exist, then go to
 * PO_Header.php to create one
 */
if (!isset($_SESSION['PO' . $identifier])) {
	header('Location:' . $RootPath . '/PO_Header.php');
	exit;
} //end if (!isset($_SESSION['PO'.$identifier]))

include('includes/header.inc');

if (!isset($_POST['Commit'])) {
	echo '<div class="toplink"><a href="' . $RootPath . '/PO_Header.php?identifier=' . $identifier . '">' . _('Back To Purchase Order Header') . '</a></div>';
} //!isset($_POST['Commit'])

if (isset($_POST['UpdateLines']) or isset($_POST['Commit'])) {
	foreach ($_SESSION['PO' . $identifier]->LineItems as $POLine) {
		if ($POLine->Deleted == false) {
			if (!is_numeric(filter_number_format($_POST['ConversionFactor' . $POLine->LineNo]))) {
				prnMsg(_('The conversion factor is expected to be numeric - the figure which converts from our units to the supplier units. e.g. if the supplier units is a tonne and our unit is a kilogram then the conversion factor that converts our unit to the suppliers unit is 1000'), 'error');
				$_SESSION['PO' . $identifier]->LineItems[$POLine->LineNo]->ConversionFactor = 1;
			} //!is_numeric(filter_number_format($_POST['ConversionFactor' . $POLine->LineNo]))
			else { //a valid number for the conversion factor is entered
				$_SESSION['PO' . $identifier]->LineItems[$POLine->LineNo]->ConversionFactor = filter_number_format($_POST['ConversionFactor' . $POLine->LineNo]);
			}
			if (!is_numeric(filter_number_format($_POST['SuppQty' . $POLine->LineNo]))) {
				prnMsg(_('The quantity in the supplier units is expected to be numeric. Please re-enter as a number'), 'error');
			} //!is_numeric(filter_number_format($_POST['SuppQty' . $POLine->LineNo]))
			else { //ok to update the PO object variables
				$_SESSION['PO' . $identifier]->LineItems[$POLine->LineNo]->Quantity = round(filter_number_format($_POST['SuppQty' . $POLine->LineNo]) * $_SESSION['PO' . $identifier]->LineItems[$POLine->LineNo]->ConversionFactor, $_SESSION['PO' . $identifier]->LineItems[$POLine->LineNo]->DecimalPlaces);
			}
			if (!is_numeric(filter_number_format($_POST['SuppPrice' . $POLine->LineNo]))) {
				prnMsg(_('The supplier price is expected to be numeric. Please re-enter as a number'), 'error');
			} //!is_numeric(filter_number_format($_POST['SuppPrice' . $POLine->LineNo]))
			else { //ok to update the PO object variables
				$_SESSION['PO' . $identifier]->LineItems[$POLine->LineNo]->Price = filter_number_format($_POST['SuppPrice' . $POLine->LineNo]) / $_SESSION['PO' . $identifier]->LineItems[$POLine->LineNo]->ConversionFactor;
			}
			$_SESSION['PO' . $identifier]->LineItems[$POLine->LineNo]->ReqDelDate = $_POST['ReqDelDate' . $POLine->LineNo];
			$_SESSION['PO' . $identifier]->LineItems[$POLine->LineNo]->ItemDescription = $_POST['ItemDescription' . $POLine->LineNo];
		} //$POLine->Deleted == false
	} //$_SESSION['PO' . $identifier]->LineItems as $POLine
} //isset($_POST['UpdateLines']) or isset($_POST['Commit'])

if (isset($_POST['Commit'])) {
	/*User wishes to commit the order to the database */

	/*First do some validation
	 *Is the delivery information all entered
	 */
	$InputError = 0;
	/*Start off assuming the best */
	if ($_SESSION['PO' . $identifier]->DelAdd1 == '' or mb_strlen($_SESSION['PO' . $identifier]->DelAdd1) < 3) {
		prnMsg(_('The purchase order cannot be committed to the database because there is no delivery street address specified'), 'error');
		$InputError = 1;
	} //$_SESSION['PO' . $identifier]->DelAdd1 == '' or mb_strlen($_SESSION['PO' . $identifier]->DelAdd1) < 3
	elseif ($_SESSION['PO' . $identifier]->Location == '' or !isset($_SESSION['PO' . $identifier]->Location)) {
		prnMsg(_('The purchase order can not be committed to the database because there is no location specified to book any stock items into'), 'error');
		$InputError = 1;
	} //$_SESSION['PO' . $identifier]->Location == '' or !isset($_SESSION['PO' . $identifier]->Location)
		elseif ($_SESSION['PO' . $identifier]->LinesOnOrder <= 0) {
		prnMsg(_('The purchase order can not be committed to the database because there are no lines entered on this order'), 'error');
		$InputError = 1;
	} //$_SESSION['PO' . $identifier]->LinesOnOrder <= 0

	/*If all clear then proceed to update the database
	 */
	if ($InputError != 1) {
		$result = DB_Txn_Begin($db);

		/*figure out what status to set the order to */
		if (IsEmailAddress($_SESSION['UserEmail'])) {
			$UserDetails = ' <a href="mailto:' . $_SESSION['UserEmail'] . '">' . $_SESSION['UsersRealName'] . '</a>';
		} //IsEmailAddress($_SESSION['UserEmail'])
		else {
			$UserDetails = ' ' . $_SESSION['UsersRealName'] . ' ';
		}
		if ($_SESSION['AutoAuthorisePO'] == 1) {
			//if the user has authority to authorise the PO then it will automatically be authorised
			$AuthSQL = "SELECT authlevel
						FROM purchorderauth
						WHERE userid='" . $_SESSION['UserID'] . "'
						AND currabrev='" . $_SESSION['PO' . $identifier]->CurrCode . "'";

			$AuthResult = DB_query($AuthSQL, $db);
			$AuthRow = DB_fetch_array($AuthResult);

			if (DB_num_rows($AuthResult) > 0 and $AuthRow['authlevel'] > $_SESSION['PO' . $identifier]->Order_Value()) { //user has authority to authrorise as well as create the order
				$StatusComment = date($_SESSION['DefaultDateFormat']) . ' - ' . _('Order Created and Authorised by') . $UserDetails . '<br />' . $_SESSION['PO' . $identifier]->StatusComments . '<br />';
				$_SESSION['PO' . $identifier]->AllowPrintPO = 1;
				$_SESSION['PO' . $identifier]->Status = 'Authorised';
			} //DB_num_rows($AuthResult) > 0 and $AuthRow['authlevel'] > $_SESSION['PO' . $identifier]->Order_Value()
			else { // no authority to authorise this order
				if (DB_num_rows($AuthResult) == 0) {
					$AuthMessage = _('Your authority to approve purchase orders in') . ' ' . $_SESSION['PO' . $identifier]->CurrCode . ' ' . _('has not yet been set up') . '<br />';
				} //DB_num_rows($AuthResult) == 0
				else {
					$AuthMessage = _('You can only authorise up to') . ' ' . $_SESSION['PO' . $identifier]->CurrCode . ' ' . $AuthRow['authlevel'] . '.<br />';
				}

				prnMsg(_('You do not have permission to authorise this purchase order') . '.<br />' . _('This order is for') . ' ' . $_SESSION['PO' . $identifier]->CurrCode . ' ' . $_SESSION['PO' . $identifier]->Order_Value() . '. ' . $AuthMessage . _('If you think this is a mistake please contact the systems administrator') . '<br />' . _('The order will be created with a status of pending and will require authorisation'), 'warn');

				$_SESSION['PO' . $identifier]->AllowPrintPO = 0;
				$StatusComment = date($_SESSION['DefaultDateFormat']) . ' - ' . _('Order Created by') . $UserDetails . '<br />' . $_SESSION['PO' . $identifier]->StatusComments . '<br />';
				$_SESSION['PO' . $identifier]->Status = 'Pending';
			}
		} //$_SESSION['AutoAuthorisePO'] == 1
		else { //auto authorise is set to off
			$_SESSION['PO' . $identifier]->AllowPrintPO = 0;
			$StatusComment = date($_SESSION['DefaultDateFormat']) . ' - ' . _('Order Created by') . $UserDetails . ' - ' . $_SESSION['PO' . $identifier]->StatusComments . '<br />';
			$_SESSION['PO' . $identifier]->Status = 'Pending';
		}

		if ($_SESSION['ExistingOrder'] == 0) {
			/*its a new order to be inserted */

			/*Get the order number */
			$_SESSION['PO' . $identifier]->OrderNo = GetNextTransNo(18, $db);

			/*Insert to purchase order header record */
			$sql = "INSERT INTO purchorders ( orderno,
											supplierno,
											comments,
											orddate,
											rate,
											initiator,
											requisitionno,
											intostocklocation,
											deladd1,
											deladd2,
											deladd3,
											deladd4,
											deladd5,
											deladd6,
											tel,
											suppdeladdress1,
											suppdeladdress2,
											suppdeladdress3,
											suppdeladdress4,
											suppdeladdress5,
											suppdeladdress6,
											suppliercontact,
											supptel,
											contact,
											version,
											revised,
											deliveryby,
											status,
											stat_comment,
											deliverydate,
											paymentterms,
											allowprint)
							VALUES(	'" . $_SESSION['PO' . $identifier]->OrderNo . "',
									'" . $_SESSION['PO' . $identifier]->SupplierID . "',
									'" . $_SESSION['PO' . $identifier]->Comments . "',
									'" . Date('Y-m-d') . "',
									'" . $_SESSION['PO' . $identifier]->ExRate . "',
									'" . $_SESSION['PO' . $identifier]->Initiator . "',
									'" . $_SESSION['PO' . $identifier]->RequisitionNo . "',
									'" . $_SESSION['PO' . $identifier]->Location . "',
									'" . $_SESSION['PO' . $identifier]->DelAdd1 . "',
									'" . $_SESSION['PO' . $identifier]->DelAdd2 . "',
									'" . $_SESSION['PO' . $identifier]->DelAdd3 . "',
									'" . $_SESSION['PO' . $identifier]->DelAdd4 . "',
									'" . $_SESSION['PO' . $identifier]->DelAdd5 . "',
									'" . $_SESSION['PO' . $identifier]->DelAdd6 . "',
									'" . $_SESSION['PO' . $identifier]->Tel . "',
									'" . $_SESSION['PO' . $identifier]->SuppDelAdd1 . "',
									'" . $_SESSION['PO' . $identifier]->SuppDelAdd2 . "',
									'" . $_SESSION['PO' . $identifier]->SuppDelAdd3 . "',
									'" . $_SESSION['PO' . $identifier]->SuppDelAdd4 . "',
									'" . $_SESSION['PO' . $identifier]->SuppDelAdd5 . "',
									'" . $_SESSION['PO' . $identifier]->SuppDelAdd6 . "',
									'" . $_SESSION['PO' . $identifier]->SupplierContact . "',
									'" . $_SESSION['PO' . $identifier]->SuppTel . "',
									'" . $_SESSION['PO' . $identifier]->Contact . "',
									'" . $_SESSION['PO' . $identifier]->Version . "',
									'" . Date('Y-m-d') . "',
									'" . $_SESSION['PO' . $identifier]->DeliveryBy . "',
									'" . $_SESSION['PO' . $identifier]->Status . "',
									'" . htmlspecialchars($StatusComment, ENT_QUOTES, 'UTF-8') . "',
									'" . FormatDateForSQL($_SESSION['PO' . $identifier]->DeliveryDate) . "',
									'" . $_SESSION['PO' . $identifier]->PaymentTerms . "',
									'" . $_SESSION['PO' . $identifier]->AllowPrintPO . "' )";

			$ErrMsg = _('The purchase order header record could not be inserted into the database because');
			$DbgMsg = _('The SQL statement used to insert the purchase order header record and failed was');
			$result = DB_query($sql, $db, $ErrMsg, $DbgMsg, true);

			/*Insert the purchase order detail records */
			foreach ($_SESSION['PO' . $identifier]->LineItems as $POLine) {
				if ($POLine->Deleted == False) {
					$sql = "INSERT INTO purchorderdetails (orderno,
														itemcode,
														deliverydate,
														itemdescription,
														glcode,
														unitprice,
														quantityord,
														shiptref,
														jobref,
														suppliersunit,
														suppliers_partno,
														assetid,
														conversionfactor )
									VALUES ('" . $_SESSION['PO' . $identifier]->OrderNo . "',
											'" . $POLine->StockID . "',
											'" . FormatDateForSQL($POLine->ReqDelDate) . "',
											'" . DB_escape_string($POLine->ItemDescription) . "',
											'" . $POLine->GLCode . "',
											'" . $POLine->Price . "',
											'" . $POLine->Quantity . "',
											'" . $POLine->ShiptRef . "',
											'" . $POLine->JobRef . "',
											'" . $POLine->SuppliersUnit . "',
											'" . $POLine->Suppliers_PartNo . "',
											'" . $POLine->AssetID . "',
											'" . $POLine->ConversionFactor . "')";
					$ErrMsg = _('One of the purchase order detail records could not be inserted into the database because');
					$DbgMsg = _('The SQL statement used to insert the purchase order detail record and failed was');

					$result = DB_query($sql, $db, $ErrMsg, $DbgMsg, true);
				} //$POLine->Deleted == False
			} //$_SESSION['PO' . $identifier]->LineItems as $POLine

			/* end of the loop round the detail line items on the order */
			prnMsg(_('Purchase Order') . ' ' . $_SESSION['PO' . $identifier]->OrderNo . ' ' . _('on') . ' ' . $_SESSION['PO' . $identifier]->SupplierName . ' ' . _('has been created'), 'success');
			if ($_SESSION['PO' . $identifier]->AllowPrintPO == 1 and ($_SESSION['PO' . $identifier]->Status == 'Authorised' or $_SESSION['PO' . $identifier]->Status == 'Printed')) {

				echo '<div class="centre"><a target="_blank" href="' . $RootPath . '/PO_PDFPurchOrder.php?OrderNo=' . $_SESSION['PO' . $identifier]->OrderNo . '">' . _('Print Purchase Order') . '</a></div>';
			}
		} //$_SESSION['ExistingOrder'] == 0
		else {
			/*its an existing order need to update the old order info */
			/*Check to see if there are any incomplete lines on the order */
			$Completed = true; //assume it is completed i.e. all lines are flagged as completed
			foreach ($_SESSION['PO' . $identifier]->LineItems as $POLine) {
				if ($POLine->Completed == 0) {
					$Completed = false;
					break;
				} //$POLine->Completed == 0
			} //$_SESSION['PO' . $identifier]->LineItems as $POLine
			if ($Completed) {
				$_SESSION['PO' . $identifier]->Status = 'Completed';
				$_SESSION['PO' . $identifier]->StatusComments = date($_SESSION['DefaultDateFormat']) . ' - ' . _('Order completed by') . $UserDetails . '<br />' . $_SESSION['PO' . $identifier]->StatusComments;
			} //$Completed
			else {
				$_SESSION['PO' . $identifier]->StatusComments = date($_SESSION['DefaultDateFormat']) . ' - ' . _('Order modified by') . $UserDetails . '<br />' . $_SESSION['PO' . $identifier]->StatusComments;
			}
			/*Update the purchase order header with any changes */

			$sql = "UPDATE purchorders SET supplierno = '" . $_SESSION['PO' . $identifier]->SupplierID . "' ,
										comments='" . $_SESSION['PO' . $identifier]->Comments . "',
										rate='" . $_SESSION['PO' . $identifier]->ExRate . "',
										initiator='" . $_SESSION['PO' . $identifier]->Initiator . "',
										requisitionno= '" . $_SESSION['PO' . $identifier]->RequisitionNo . "',
										version= '" . $_SESSION['PO' . $identifier]->Version . "',
										deliveryby='" . $_SESSION['PO' . $identifier]->DeliveryBy . "',
										deliverydate='" . FormatDateForSQL($_SESSION['PO' . $identifier]->DeliveryDate) . "',
										revised= '" . Date('Y-m-d') . "',
										intostocklocation='" . $_SESSION['PO' . $identifier]->Location . "',
										deladd1='" . $_SESSION['PO' . $identifier]->DelAdd1 . "',
										deladd2='" . $_SESSION['PO' . $identifier]->DelAdd2 . "',
										deladd3='" . $_SESSION['PO' . $identifier]->DelAdd3 . "',
										deladd4='" . $_SESSION['PO' . $identifier]->DelAdd4 . "',
										deladd5='" . $_SESSION['PO' . $identifier]->DelAdd5 . "',
										deladd6='" . $_SESSION['PO' . $identifier]->DelAdd6 . "',
										tel='" . $_SESSION['PO' . $identifier]->Tel . "',
										suppdeladdress1='" . $_SESSION['PO' . $identifier]->SuppDelAdd1 . "',
										suppdeladdress2='" . $_SESSION['PO' . $identifier]->SuppDelAdd2 . "',
										suppdeladdress3='" . $_SESSION['PO' . $identifier]->SuppDelAdd3 . "',
										suppdeladdress4='" . $_SESSION['PO' . $identifier]->SuppDelAdd4 . "',
										suppdeladdress5='" . $_SESSION['PO' . $identifier]->SuppDelAdd5 . "',
										suppdeladdress6='" . $_SESSION['PO' . $identifier]->SuppDelAdd6 . "',
										suppliercontact='" . $_SESSION['PO' . $identifier]->SupplierContact . "',
										supptel='" . $_SESSION['PO' . $identifier]->SuppTel . "',
										contact='" . $_SESSION['PO' . $identifier]->Contact . "',
										paymentterms='" . $_SESSION['PO' . $identifier]->PaymentTerms . "',
										allowprint='" . $_SESSION['PO' . $identifier]->AllowPrintPO . "',
										status = '" . $_SESSION['PO' . $identifier]->Status . "',
										stat_comment = '" . htmlspecialchars($_SESSION['PO' . $identifier]->StatusComments, ENT_QUOTES, 'UTF-8') . "'
										WHERE orderno = '" . $_SESSION['PO' . $identifier]->OrderNo . "'";

			$ErrMsg = _('The purchase order could not be updated because');
			$DbgMsg = _('The SQL statement used to update the purchase order header record, that failed was');
			$result = DB_query($sql, $db, $ErrMsg, $DbgMsg, true);

			/*Now Update the purchase order detail records */
			foreach ($_SESSION['PO' . $identifier]->LineItems as $POLine) {
				if ($POLine->Deleted == true) {
					if ($POLine->PODetailRec != '') {
						$sql = "DELETE FROM purchorderdetails WHERE podetailitem='" . $POLine->PODetailRec . "'";
						$ErrMsg = _('The purchase order detail line could not be deleted because');
						$DbgMsg = _('The SQL statement used to delete the purchase order detail record, that failed was');
						$result = DB_query($sql, $db, $ErrMsg, $DbgMsg, true);
					} //$POLine->PODetailRec != ''
				} //$POLine->Deleted == true
				else if ($POLine->PODetailRec == '') {
					/*When the purchase order line is an existing record the auto-increment
					 * field PODetailRec is given to the session for that POLine
					 * So it will only be a new POLine if PODetailRec is empty
					 */
					$sql = "INSERT INTO purchorderdetails ( orderno,
														itemcode,
														deliverydate,
														itemdescription,
														glcode,
														unitprice,
														quantityord,
														shiptref,
														jobref,
														suppliersunit,
														suppliers_partno,
														assetid,
														conversionfactor)
													VALUES (
														'" . $_SESSION['PO' . $identifier]->OrderNo . "',
														'" . $POLine->StockID . "',
														'" . FormatDateForSQL($POLine->ReqDelDate) . "',
														'" . DB_escape_string($POLine->ItemDescription) . "',
														'" . $POLine->GLCode . "',
														'" . $POLine->Price . "',
														'" . $POLine->Quantity . "',
														'" . $POLine->ShiptRef . "',
														'" . $POLine->JobRef . "',
														'" . $POLine->SuppliersUnit . "',
														'" . $POLine->Suppliers_PartNo . "',
														'" . $POLine->AssetID . "',
														'" . $POLine->ConversionFactor . "')";

				} //$POLine->PODetailRec == ''
				else {
					if ($POLine->Quantity == $POLine->QtyReceived) {
						$sql = "UPDATE purchorderdetails SET itemcode='" . $POLine->StockID . "',
															deliverydate ='" . FormatDateForSQL($POLine->ReqDelDate) . "',
															itemdescription='" . DB_escape_string($POLine->ItemDescription) . "',
															glcode='" . $POLine->GLCode . "',
															unitprice='" . $POLine->Price . "',
															quantityord='" . $POLine->Quantity . "',
															shiptref='" . $POLine->ShiptRef . "',
															jobref='" . $POLine->JobRef . "',
															suppliersunit='" . $POLine->SuppliersUnit . "',
															suppliers_partno='" . DB_escape_string($POLine->Suppliers_PartNo) . "',
															completed=1,
															assetid='" . $POLine->AssetID . "',
															conversionfactor = '" . $POLine->ConversionFactor . "'
								WHERE podetailitem='" . $POLine->PODetailRec . "'";
					} //$POLine->Quantity == $POLine->QtyReceived
					else {
						$sql = "UPDATE purchorderdetails SET itemcode='" . $POLine->StockID . "',
															deliverydate ='" . FormatDateForSQL($POLine->ReqDelDate) . "',
															itemdescription='" . DB_escape_string($POLine->ItemDescription) . "',
															glcode='" . $POLine->GLCode . "',
															unitprice='" . $POLine->Price . "',
															quantityord='" . $POLine->Quantity . "',
															shiptref='" . $POLine->ShiptRef . "',
															jobref='" . $POLine->JobRef . "',
															suppliersunit='" . $POLine->SuppliersUnit . "',
															suppliers_partno='" . $POLine->Suppliers_PartNo . "',
															assetid='" . $POLine->AssetID . "',
															conversionfactor = '" . $POLine->ConversionFactor . "'
								WHERE podetailitem='" . $POLine->PODetailRec . "'";
					}
				}

				$ErrMsg = _('One of the purchase order detail records could not be updated because');
				$DbgMsg = _('The SQL statement used to update the purchase order detail record that failed was');
				$result = DB_query($sql, $db, $ErrMsg, $DbgMsg, true);

			} //$_SESSION['PO' . $identifier]->LineItems as $POLine

			/* end of the loop round the detail line items on the order */
			prnMsg(_('Purchase Order') . ' ' . $_SESSION['PO' . $identifier]->OrderNo . ' ' . _('has been updated'), 'success');
			if ($_SESSION['PO' . $identifier]->AllowPrintPO == 1 and ($_SESSION['PO' . $identifier]->Status == 'Authorised' or $_SESSION['PO' . $identifier]->Status == 'Printed')) {
				echo '<br /><div class="centre"><a target="_blank" href="' . $RootPath . '/PO_PDFPurchOrder.php?OrderNo=' . $_SESSION['PO' . $identifier]->OrderNo . '">' . _('Print Purchase Order') . '</a></div>';
			} //$_SESSION['PO' . $identifier]->AllowPrintPO == 1 and ($_SESSION['PO' . $identifier]->Status == 'Authorised' or $_SESSION['PO' . $identifier]->Status == 'Printed')

		}
		/*end of if its a new order or an existing one */


		$Result = DB_Txn_Commit($db);
		/* Only show the link to auto receive the order if the user has permission to receive goods and permission to authorise and has authorised the order */
		if ($_SESSION['PO' . $identifier]->Status == 'Authorised' and in_array($_SESSION['PageSecurityArray']['GoodsReceived.php'], $_SESSION['AllowedPageSecurityTokens'])) {

			echo '<a href="SupplierInvoice.php?SupplierID=' . $_SESSION['PO' . $identifier]->SupplierID . '&amp;ReceivePO=' . $_SESSION['PO' . $identifier]->OrderNo . '&amp;DeliveryDate=' . $_SESSION['PO' . $identifier]->DeliveryDate . '">' . _('Receive and Enter Purchase Invoice') . '</a>';
		} //$_SESSION['PO' . $identifier]->Status == 'Authorised' and in_array(1001, $_SESSION['AllowedPageSecurityTokens'])

		unset($_SESSION['PO' . $identifier]);
		/*Clear the PO data to allow a newy to be input*/
		include('includes/footer.inc');
		exit;
	} //$InputError != 1

	/*end if there were no input errors trapped */
} //isset($_POST['Commit'])

/* end of the code to do transfer the PO object to the database  - user hit the place PO*/


/* Always do the stuff below if not looking for a supplierid */

if (isset($_GET['Delete'])) {
	if ($_SESSION['PO' . $identifier]->Some_Already_Received($_GET['Delete']) == 0) {
		$_SESSION['PO' . $identifier]->remove_from_order($_GET['Delete']);
		include('includes/PO_UnsetFormVbls.php');
	} //$_SESSION['PO' . $identifier]->Some_Already_Received($_GET['Delete']) == 0
	else {
		prnMsg(_('This item cannot be deleted because some of it has already been received'), 'warn');
	}
} //isset($_GET['Delete'])

if (isset($_GET['Complete'])) {
	$_SESSION['PO' . $identifier]->LineItems[$_GET['Complete']]->Completed = 1;
} //isset($_GET['Complete'])

if (isset($_POST['EnterLine'])) {
	/*Inputs from the form directly without selecting a stock item from the search */

	$AllowUpdate = true;
	/*always assume the best */
	if (!is_numeric(filter_number_format($_POST['Qty']))) {
		$AllowUpdate = false;
		prnMsg(_('Cannot Enter this order line') . '<br />' . _('The quantity of the order item must be numeric'), 'error');
	} //!is_numeric(filter_number_format($_POST['Qty']))
	if (filter_number_format($_POST['Qty']) < 0) {
		$AllowUpdate = false;
		prnMsg(_('Cannot Enter this order line') . '<br />' . _('The quantity of the ordered item entered must be a positive amount'), 'error');
	} //filter_number_format($_POST['Qty']) < 0
	if (!is_numeric(filter_number_format($_POST['Price']))) {
		$AllowUpdate = false;
		prnMsg(_('Cannot Enter this order line') . '<br />' . _('The price entered must be numeric'), 'error');
	} //!is_numeric(filter_number_format($_POST['Price']))
	if (!Is_Date($_POST['ReqDelDate'])) {
		$AllowUpdate = False;
		prnMsg(_('Cannot Enter this order line') . '</b><br />' . _('The date entered must be in the format') . ' ' . $_SESSION['DefaultDateFormat'], 'error');
	} //!Is_Date($_POST['ReqDelDate'])

	/*It's not a stock item
	 * need to check GL Code is valid if GLLink is active
	 * [icedlava] GL Code is required for non stock item variance in price vs purchase order when supplier invoice generated even if stock not linked to GL, but AP is else
	 * there will be an sql error  in SupplierInvoice.php without a valid GL Code
	 */
	if ($_SESSION['PO'.$identifier]->GLLink == 1 or $_SESSION['CompanyRecord']['gllink_creditors'] == 1) {
		$sql = "SELECT accountname
					FROM chartmaster
					WHERE accountcode ='" . $_POST['GLCode'] . "'";
		$ErrMsg = _('The account details for') . ' ' . $_POST['GLCode'] . ' ' . _('could not be retrieved because');
		$DbgMsg = _('The SQL used to retrieve the details of the account, but failed was');
		$GLValidResult = DB_query($sql, $db, $ErrMsg, $DbgMsg, false, false);
		if (DB_error_no($db) != 0) {
			$AllowUpdate = false;
			prnMsg(_('The validation process for the GL Code entered could not be executed because') . ' ' . DB_error_msg($db), 'error');
			if ($debug == 1) {
				prnMsg(_('The SQL used to validate the code entered was') . ' ' . $sql, 'error');
			} //$debug == 1
			include('includes/footer.inc');
			exit;
		} //DB_error_no($db) != 0
		if (DB_num_rows($GLValidResult) == 0) {
			/*The GLCode entered does not exist */
			$AllowUpdate = false;
			prnMsg(_('Cannot enter this order line') . ':<br />' . _('The general ledger code') . ' - ' . $_POST['GLCode'] . ' ' . _('is not a general ledger code that is defined in the chart of accounts') . ' . ' . _('Please use a code that is already defined') . '. ' . _('See the Chart list from the link below'), 'error');
		} //DB_num_rows($GLValidResult) == 0
		else {
			$myrow = DB_fetch_row($GLValidResult);
			$GLAccountName = $myrow[0];
		}
	} /* dont bother checking the GL Code if there is no GL code to check ie not linked to GL */
	else {
		$_POST['GLCode'] = 0;
	}

	if ($_POST['AssetID'] != 'Not an Asset') {
		$ValidAssetResult = DB_query("SELECT assetid,
											description,
											costact
										FROM fixedassets
										INNER JOIN fixedassetcategories
										ON fixedassets.assetcategoryid=fixedassetcategories.categoryid
										WHERE assetid='" . $_POST['AssetID'] . "'", $db);
		if (DB_num_rows($ValidAssetResult) == 0) { // then the asset id entered doesn't exist
			$AllowUpdate = false;
			prnMsg(_('An asset code was entered but it does not yet exist. Only pre-existing asset ids can be entered when ordering a fixed asset'), 'error');
		} //DB_num_rows($ValidAssetResult) == 0
		else {
			$AssetRow = DB_fetch_array($ValidAssetResult);
			$_POST['GLCode'] = $AssetRow['costact'];
			if ($_POST['ItemDescription'] == '') {
				$_POST['ItemDescription'] = $AssetRow['description'];
			} //$_POST['ItemDescription'] == ''
		}
	} //$_POST['AssetID'] != 'Not an Asset'

	/*end if an AssetID is entered */
	else {
		$_POST['AssetID'] = 0; // cannot commit a string to an integer field so make it 0 if AssetID = 'Not an Asset'
	}
	if (mb_strlen($_POST['ItemDescription']) <= 3) {
		$AllowUpdate = false;
		prnMsg(_('Cannot enter this order line') . ':<br />' . _('The description of the item being purchased is required where a non-stock item is being ordered'), 'warn');
	} //mb_strlen($_POST['ItemDescription']) <= 3

	if ($AllowUpdate == true) {
		//adding the non-stock item

		$_SESSION['PO' . $identifier]->add_to_order($_SESSION['PO' . $identifier]->LinesOnOrder + 1, '', 0, /*Serialised */ 0, /*Controlled */ filter_number_format($_POST['Qty']), $_POST['ItemDescription'], filter_number_format($_POST['Price']), $_POST['SuppliersUnit'], $_POST['GLCode'], $_POST['ReqDelDate'], '', 0, '', 0, 0, $GLAccountName, 2, $_POST['SuppliersUnit'], 1, 1, '', $_POST['AssetID']);
		include('includes/PO_UnsetFormVbls.php');
	} //$AllowUpdate == true
} //isset($_POST['EnterLine'])

/*end if Enter line button was hit - adding non stock items */

//Add variables $_SESSION['PO_ItemsResubmitForm' . $identifier] and $_POST['PO_ItemsResubmitFormValue'] to prevent from page refreshing effect

$_SESSION['PO_ItemsResubmitForm' . $identifier] = (empty($_SESSION['PO_ItemsResubmitForm' . $identifier])) ? '1' : $_SESSION['PO_ItemsResubmitForm' . $identifier];
if (isset($_POST['NewItem']) and !empty($_POST['PO_ItemsResubmitFormValue']) and $_SESSION['PO_ItemsResubmitForm' . $identifier] == $_POST['PO_ItemsResubmitFormValue']) { //only submit values can be processed

	/* NewItem is set from the part selection list as the part code selected
	 * take the form entries and enter the data from the form into the PurchOrder class variable
	 * A series of form variables of the format "NewQty" with the ItemCode concatenated are created on the search for adding new
	 * items for each of these form variables need to parse out the item code and look up the details to add them to the purchase
	 * order  $_POST is of course the global array of all posted form variables
	 */

	foreach ($_POST as $FormVariableName => $Quantity) {
		/*The form entity name is of the format NewQtyX where X is the index number that identifies the stock item code held in the hidden StockIDX form variable
		 * */
		if (mb_substr($FormVariableName, 0, 6) == 'NewQty' and filter_number_format($Quantity) != 0) { //if the form variable represents a Qty to add to the order

			$ItemCode = $_POST['StockID' . mb_substr($FormVariableName, 6)];
			$AlreadyOnThisOrder = 0;

			if ($_SESSION['PO_AllowSameItemMultipleTimes'] == false) {
				if (count($_SESSION['PO' . $identifier]->LineItems) != 0) {
					foreach ($_SESSION['PO' . $identifier]->LineItems as $OrderItem) {
						/* do a loop round the items on the order to see that the item is not already on this order */
						if (($OrderItem->StockID == $ItemCode) and ($OrderItem->Deleted == false)) {
							$AlreadyOnThisOrder = 1;
							prnMsg(_('The item') . ' ' . $ItemCode . ' ' . _('is already on this order') . '. ' . _('The system will not allow the same item on the order more than once') . '. ' . _('However you can change the quantity ordered of the existing line if necessary'), 'error');
						} //($OrderItem->StockID == $ItemCode) and ($OrderItem->Deleted == false)
					} //$_SESSION['PO' . $identifier]->LineItems as $OrderItem

					/* end of the foreach loop to look for preexisting items of the same code */
				} //count($_SESSION['PO' . $identifier]->LineItems) != 0
			} //$_SESSION['PO_AllowSameItemMultipleTimes'] == false
			if ($AlreadyOnThisOrder != 1 and filter_number_format($Quantity) > 0) {
				$sql = "SELECT description,
							longdescription,
							stockid,
							units,
							decimalplaces,
							stockact,
							accountname
						FROM stockmaster INNER JOIN stockcategory
						ON stockcategory.categoryid = stockmaster.categoryid
						INNER JOIN chartmaster
						ON chartmaster.accountcode = stockcategory.stockact
						WHERE  stockmaster.stockid = '" . $ItemCode . "'";

				$ErrMsg = _('The item details for') . ' ' . $ItemCode . ' ' . _('could not be retrieved because');
				$DbgMsg = _('The SQL used to retrieve the item details but failed was');
				$ItemResult = DB_query($sql, $db, $ErrMsg, $DbgMsg);
				if (DB_num_rows($ItemResult) == 1) {
					$ItemRow = DB_fetch_array($ItemResult);

					$sql = "SELECT price,
								conversionfactor,
								supplierdescription,
								suppliersuom,
								suppliers_partno,
								leadtime,
								MAX(purchdata.effectivefrom) AS latesteffectivefrom
							FROM purchdata
							WHERE purchdata.supplierno = '" . $_SESSION['PO' . $identifier]->SupplierID . "'
							AND purchdata.effectivefrom <='" . Date('Y-m-d') . "'
							AND purchdata.stockid = '" . $ItemCode . "'
							GROUP BY purchdata.price,
									purchdata.conversionfactor,
									purchdata.supplierdescription,
									purchdata.suppliersuom,
									purchdata.suppliers_partno,
									purchdata.leadtime
							ORDER BY latesteffectivefrom DESC";

					$ErrMsg = _('The purchasing data for') . ' ' . $ItemCode . ' ' . _('could not be retrieved because');
					$DbgMsg = _('The SQL used to retrieve the purchasing data but failed was');
					$PurchDataResult = DB_query($sql, $db, $ErrMsg, $DbgMsg);
					if (DB_num_rows($PurchDataResult) > 0) { //the purchasing data is set up
						$PurchRow = DB_fetch_array($PurchDataResult);
						/* Now to get the applicable discounts */
						$sql = "SELECT discountpercent,
										discountamount
								FROM supplierdiscounts
								WHERE supplierno= '" . $_SESSION['PO' . $identifier]->SupplierID . "'
								AND effectivefrom <='" . Date('Y-m-d') . "'
								AND effectiveto >='" . Date('Y-m-d') . "'
								AND stockid = '" . $ItemCode . "'";

						$ItemDiscountPercent = 0;
						$ItemDiscountAmount = 0;
						$ErrMsg = _('Could not retrieve the supplier discounts applicable to the item');
						$DbgMsg = _('The SQL used to retrive the supplier discounts that failed was');
						$DiscountResult = DB_query($sql, $db, $ErrMsg, $DbgMsg);
						while ($DiscountRow = DB_fetch_array($DiscountResult)) {
							$ItemDiscountPercent += $DiscountRow['discountpercent'];
							$ItemDiscountAmount += $DiscountRow['discountamount'];
						}
						if ($ItemDiscountPercent != 0) {
							prnMsg(_('Taken accumulated supplier percentage discounts of') . ' ' . locale_number_format($ItemDiscountPercent * 100, 2) . '%', 'info');
						}
						if ($ItemDiscountAmount != 0) {
							prnMsg(_('Taken accumulated round sum supplier discount of') . ' ' . $_SESSION['PO' . $identifier]->CurrCode . ' ' . locale_number_format($ItemDiscountAmount, $_SESSION['PO' . $identifier]->CurrDecimalPlaces) . ' (' . _('per supplier unit') . ')', 'info');
						}
						$PurchPrice = ($PurchRow['price'] * (1 - $ItemDiscountPercent) - $ItemDiscountAmount) / $PurchRow['conversionfactor'];
						$ConversionFactor = $PurchRow['conversionfactor'];
						if ($PurchRow['suppliers_partno'] != $ItemCode) { //only show supplier's part code if not the same as our item code
							$SupplierDescription = $PurchRow['suppliers_partno'] . ' - ';
						} else {
							$SupplierDescription = '';
						}
						if (mb_strlen($PurchRow['supplierdescription']) > 2) {
							$SupplierDescription .= $PurchRow['supplierdescription'];
						} //mb_strlen($PurchRow['supplierdescription']) > 2
						else {
							$SupplierDescription .= $ItemRow['description'];
						}
						$SuppliersUnitOfMeasure = $PurchRow['suppliersuom'];
						$SuppliersPartNo = $PurchRow['suppliers_partno'];
						$LeadTime = $PurchRow['leadtime'];
						/* Work out the delivery date based on today + lead time
						 * if > header DeliveryDate then set DeliveryDate to today + leadtime
						 */
						$DeliveryDate = DateAdd(Date($_SESSION['DefaultDateFormat']), 'd', $LeadTime);
						if (Date1GreaterThanDate2($_SESSION['PO' . $identifier]->DeliveryDate, $DeliveryDate)) {
							$DeliveryDate = $_SESSION['PO' . $identifier]->DeliveryDate;
						} //!Date1GreaterThanDate2($DeliveryDate, $_SESSION['PO' . $identifier]->DeliveryDate)
					} //DB_num_rows($PurchDataResult) > 0
					else { // no purchasing data setup
						$PurchPrice = 0;
						$ConversionFactor = 1;
						$SupplierDescription = $ItemRow['description'];
						$SuppliersUnitOfMeasure = $ItemRow['units'];
						$SuppliersPartNo = '';
						$LeadTime = 1;
						$DeliveryDate = $_SESSION['PO' . $identifier]->DeliveryDate;
					}

					$_SESSION['PO' . $identifier]->add_to_order($_SESSION['PO' . $identifier]->LinesOnOrder + 1, $ItemCode, 0, /*Serialised */ 0, /*Controlled */ filter_number_format($Quantity) * $ConversionFactor, /* Qty */ $SupplierDescription, $PurchPrice, $ItemRow['units'], $ItemRow['stockact'], $DeliveryDate, 0, 0, 0, 0, 0, $ItemRow['accountname'], $ItemRow['decimalplaces'], $SuppliersUnitOfMeasure, $ConversionFactor, $LeadTime, $SuppliersPartNo);
				} //DB_num_rows($ItemResult) == 1
				else { //no rows returned by the SQL to get the item
					prnMsg(_('The item code') . ' ' . $ItemCode . ' ' . _('does not exist in the database and therefore cannot be added to the order'), 'error');
					if ($debug == 1) {
						echo '<br />' . $sql;
					} //$debug == 1
					include('includes/footer.inc');
					exit;
				}
			} //$AlreadyOnThisOrder != 1 and filter_number_format($Quantity) > 0

			/* end of if not already on the order */
		} //mb_substr($FormVariableName, 0, 6) == 'NewQty' and filter_number_format($Quantity) != 0

		/* end if the $_POST has NewQty in the variable name */
	} //$_POST as $FormVariableName => $Quantity

	/* end loop around the $_POST array */
	$_SESSION['PO_ItemsResubmitForm' . $identifier]++; //change the $_SESSION VALUE
} //isset($_POST['NewItem']) and !empty($_POST['PO_ItemsResubmitFormValue']) and $_SESSION['PO_ItemsResubmitForm' . $identifier] == $_POST['PO_ItemsResubmitFormValue']

/* end of if its a new item */

/* This is where the order as selected should be displayed  reflecting any deletions or insertions*/

echo '<form onSubmit="return VerifyForm(this);" id="form1" action="' . htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8') . '?identifier=' . $identifier . '" method="post" class="noPrint">';
echo '<input type="hidden" name="FormID" value="' . $_SESSION['FormID'] . '" />';

/*need to set up entry for item description where not a stock item and GL Codes */

if (count($_SESSION['PO' . $identifier]->LineItems) > 0 and !isset($_GET['Edit'])) {
	echo '<p class="page_title_text noPrint" ><img src="' . $RootPath . '/css/' . $Theme . '/images/supplier.png" title="' . _('Purchase Order') . '" alt="" />  ' . $_SESSION['PO' . $identifier]->SupplierName;

	if (isset($_SESSION['PO' . $identifier]->OrderNo)) {
		echo ' ' . _('Purchase Order') . ' ' . $_SESSION['PO' . $identifier]->OrderNo;
	} //isset($_SESSION['PO' . $identifier]->OrderNo)
	echo '<br /><b>' . _(' Order Summary') . '</b></p>';
	echo '<table cellpadding="2" class="selection">';
	echo '<tr>
			<th class="SortableColumn">' . _('Item Code') . '</th>
			<th class="SortableColumn">' . _('Description') . '</th>
			<th>' . _('Quantity Our Units') . '</th>
			<th>' . _('Our Unit') . '</th>
			<th>' . _('Price Our Units') . ' (' . $_SESSION['PO' . $identifier]->CurrCode . ')</th>
			<th>' . _('Unit Conversion Factor') . '</th>
			<th>' . _('Order Quantity') . '<br />' . _('Supplier Units') . '</th>
			<th>' . _('Supplier Unit') . '</th>
			<th>' . _('Order Price') . '<br />' . _('Supp Units') . ' (' . $_SESSION['PO' . $identifier]->CurrCode . ')</th>
			<th>' . _('Sub-Total') . ' (' . $_SESSION['PO' . $identifier]->CurrCode . ')</th>
			<th>' . _('Deliver By') . '</th>
			</tr>';

	$_SESSION['PO' . $identifier]->Total = 0;
	$k = 0; //row colour counter

	foreach ($_SESSION['PO' . $identifier]->LineItems as $POLine) {
		if ($POLine->Deleted == False) {
			$LineTotal = $POLine->Quantity * $POLine->Price;
			$DisplayLineTotal = locale_number_format($LineTotal, $_SESSION['PO' . $identifier]->CurrDecimalPlaces);
			// Note if the price is greater than 1 use 2 decimal place, if the price is a fraction of 1, use 4 decimal places
			// This should help display where item-price is a fraction
			if ($POLine->Price > 1) {
				$DisplayPrice = locale_number_format($POLine->Price, $_SESSION['PO' . $identifier]->CurrDecimalPlaces);
				$SuppPrice = locale_number_format(round(($POLine->Price * $POLine->ConversionFactor), $_SESSION['PO' . $identifier]->CurrDecimalPlaces), $_SESSION['PO' . $identifier]->CurrDecimalPlaces);
			} //$POLine->Price > 1
			else {
				$DisplayPrice = locale_number_format($POLine->Price, 4);
				$SuppPrice = locale_number_format(round(($POLine->Price * $POLine->ConversionFactor), 4), 4);
			}

			if ($k == 1) {
				echo '<tr class="EvenTableRows">';
				$k = 0;
			} //$k == 1
			else {
				echo '<tr class="OddTableRows">';
				$k = 1;
			}

			echo '<td>' . $POLine->StockID . '</td>
				<td><input type="text" name="ItemDescription' . $POLine->LineNo . '" size="30" value="' . stripslashes($POLine->ItemDescription) . '" /></td>
				<td class="number">' . locale_number_format($POLine->Quantity, $POLine->DecimalPlaces) . '</td>
				<td>' . $POLine->Units . '</td>
				<td class="number">' . $DisplayPrice . '</td>
				<td><input type="text" class="number" required="required" minlength="1" maxlength="8" name="ConversionFactor' . $POLine->LineNo . '" size="8" value="' . locale_number_format($POLine->ConversionFactor, 'Variable') . '" /></td>
				<td><input type="text" class="number" required="required" minlength="1" maxlength="10" name="SuppQty' . $POLine->LineNo . '" size="10" value="' . locale_number_format(round($POLine->Quantity / $POLine->ConversionFactor, $POLine->DecimalPlaces), $POLine->DecimalPlaces) . '" /></td>
				<td>' . $POLine->SuppliersUnit . '</td>
				<td><input type="text" class="number" name="SuppPrice' . $POLine->LineNo . '" size="10" value="' . $SuppPrice . '" /></td>
				<td class="number">' . $DisplayLineTotal . '</td>
				<td><input type="text" class="date" required="required" minlength="1" maxlength="10" alt="' . $_SESSION['DefaultDateFormat'] . '" name="ReqDelDate' . $POLine->LineNo . '" size="10" value="' . $POLine->ReqDelDate . '" /></td>';
			if ($POLine->QtyReceived != 0 and $POLine->Completed != 1) {
				echo '<td><a href="' . htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8') . '?identifier=' . $identifier . '&amp;Complete=' . $POLine->LineNo . '">' . _('Complete') . '</a></td>';
			} //$POLine->QtyReceived != 0 and $POLine->Completed != 1
			elseif ($POLine->QtyReceived == 0) {
				echo '<td><a href="' . htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8') . '?identifier=' . $identifier . '&amp;Delete=' . $POLine->LineNo . '">' . _('Delete') . '</a></td>';
			} //$POLine->QtyReceived == 0
			echo '</tr>';
			$_SESSION['PO' . $identifier]->Total += $LineTotal;
		} //$POLine->Deleted == False
	} //$_SESSION['PO' . $identifier]->LineItems as $POLine

	$DisplayTotal = locale_number_format($_SESSION['PO' . $identifier]->Total, $_SESSION['PO' . $identifier]->CurrDecimalPlaces);
	echo '<tr>
			<td colspan="9" class="number">' . _('TOTAL') . _(' excluding Tax') . '</td>
			<td class="number"><b>' . $DisplayTotal . '</b></td>
		</tr>
	</table>';
	echo '<div class="centre">
			<input type="submit" name="UpdateLines" value="' . _('Update Order Lines') . '" />
			&nbsp;<input type="submit" name="Commit" value="' . _('Process Order') . '" />
		</div>';

} //count($_SESSION['PO' . $identifier]->LineItems) > 0 and !isset($_GET['Edit'])

/*Only display the order line items if there are any !! */


if (isset($_POST['NonStockOrder'])) {
	echo '<br /><table class="selection"><tr>
				<td>' . _('Item Description') . '</td>';
	echo '<td><input type="text" name="ItemDescription" size="40" /></td></tr>';
	echo '<tr>
			<td>' . _('General Ledger Code') . '</td>
			<td><select minlength="0" name="GLCode">';
	$sql = "SELECT accountcode,
				  accountname
				FROM chartmaster
				ORDER BY accountcode ASC";

	$result = DB_query($sql, $db);
	while ($myrow = DB_fetch_array($result)) {
		echo '<option value="' . $myrow['accountcode'] . '">' . $myrow['accountcode'] . ' - ' . $myrow['accountname'] . '</option>';
	} //$myrow = DB_fetch_array($result)
	echo '</select></td></tr>';
	echo '<tr>
			<td>' . _('OR Asset ID') . '</td>
			<td><select required="required" minlength="1" name="AssetID">';

	$sql = "SELECT assetid,
					description,
					datepurchased
				FROM fixedassets
				ORDER BY assetid DESC";
	$AssetsResult = DB_query($sql, $db);
	echo '<option selected="selected" value="Not an Asset">' . _('Not an Asset') . '</option>';
	while ($AssetRow = DB_fetch_array($AssetsResult)) {
		if ($AssetRow['datepurchased'] == '0000-00-00') {
			$DatePurchased = _('Not yet purchased');
		} //$AssetRow['datepurchased'] == '0000-00-00'
		else {
			$DatePurchased = ConvertSQLDate($AssetRow['datepurchased']);
		}
		echo '<option value="' . $AssetRow['assetid'] . '">' . $AssetRow['assetid'] . ' - ' . $DatePurchased . ' - ' . $AssetRow['description'] . '</option>';
	} //$AssetRow = DB_fetch_array($AssetsResult)

	echo '</select><a href="FixedAssetItems.php" target=_blank>' . _('New Fixed Asset') . '</a></td></tr>
		<tr>
			<td>' . _('Quantity to purchase') . '</td>
			<td><input type="text" class="number" name="Qty" required="required" minlength="1" maxlength="10" size="10" value="1" /></td>
		</tr>
		<tr>
			<td>' . _('Price per item') . '</td>
			<td><input type="text" required="required" minlength="1" maxlength="10" class="number" name="Price" size="10" /></td>
		</tr>
		<tr>
			<td>' . _('Unit') . '</td>
			<td><input type="text" required="required" minlength="1" maxlength="10" name="SuppliersUnit" size="10" value="' . _('each') . '" /></td>
		</tr>
		<tr>
			<td>' . _('Delivery Date') . '</td>
			<td><input type="text" class="date" required="required" minlength="1" maxlength="10" alt="' . $_SESSION['DefaultDateFormat'] . '" name="ReqDelDate" size="11" value="' . $_SESSION['PO' . $identifier]->DeliveryDate . '" /></td>
		</tr>
		</table>
		<div class="centre">
			<input type="submit" name="EnterLine" value="' . _('Enter Item') . '" />
		</div>';
} //isset($_POST['NonStockOrder'])

/* Now show the stock item selection search stuff below */
if (isset($_POST['Search'])) {
	/*ie seach for stock items */

	if ($_POST['Keywords'] and $_POST['StockCode']) {
		prnMsg(_('Stock description keywords have been used in preference to the Stock code extract entered'), 'info');
	} //$_POST['Keywords'] and $_POST['StockCode']
	if ($_POST['Keywords']) {
		//insert wildcard characters in spaces
		$SearchString = '%' . str_replace(' ', '%', $_POST['Keywords']) . '%';

		if ($_POST['StockCat'] == 'All') {
			if ($_POST['SupplierItemsOnly'] == 'on') {
				$sql = "SELECT stockmaster.stockid,
								stockmaster.description,
								stockmaster.units
						FROM stockmaster INNER JOIN stockcategory
						ON stockmaster.categoryid=stockcategory.categoryid
						INNER JOIN purchdata
						ON stockmaster.stockid=purchdata.stockid
						WHERE stockmaster.mbflag<>'D'
						AND stockmaster.mbflag<>'A'
						AND stockmaster.mbflag<>'K'
						AND stockmaster.mbflag<>'G'
						AND stockmaster.discontinued<>1
						AND purchdata.supplierno='" . $_SESSION['PO' . $identifier]->SupplierID . "'
						AND stockmaster.description " . LIKE . " '" . $SearchString . "'
						ORDER BY stockmaster.stockid
						LIMIT " . $_SESSION['DisplayRecordsMax'];
			} //$_POST['SupplierItemsOnly'] == 'on'
			else { // not just supplier purchdata items

				$sql = "SELECT stockmaster.stockid,
							stockmaster.description,
							stockmaster.units
					FROM stockmaster INNER JOIN stockcategory
					ON stockmaster.categoryid=stockcategory.categoryid
					WHERE stockmaster.mbflag<>'D'
					AND stockmaster.mbflag<>'A'
					AND stockmaster.mbflag<>'K'
					AND stockmaster.mbflag<>'G'
					AND stockmaster.discontinued<>1
					AND stockmaster.description " . LIKE . " '" . $SearchString . "'
					ORDER BY stockmaster.stockid
					LIMIT " . $_SESSION['DisplayRecordsMax'];
			}
		} //$_POST['StockCat'] == 'All'
		else { //for a specific stock category
			if ($_POST['SupplierItemsOnly'] == 'on') {
				$sql = "SELECT stockmaster.stockid,
								stockmaster.description,
								stockmaster.units
						FROM stockmaster INNER JOIN stockcategory
						ON stockmaster.categoryid=stockcategory.categoryid
						INNER JOIN purchdata
						ON stockmaster.stockid=purchdata.stockid
						WHERE stockmaster.mbflag<>'D'
						AND stockmaster.mbflag<>'A'
						AND stockmaster.mbflag<>'K'
						AND stockmaster.mbflag<>'G'
						AND purchdata.supplierno='" . $_SESSION['PO' . $identifier]->SupplierID . "'
						AND stockmaster.discontinued<>1
						AND stockmaster.description " . LIKE . " '" . $SearchString . "'
						AND stockmaster.categoryid='" . $_POST['StockCat'] . "'
						ORDER BY stockmaster.stockid
						LIMIT " . $_SESSION['DisplayRecordsMax'];
			} //$_POST['SupplierItemsOnly'] == 'on'
			else {
				$sql = "SELECT stockmaster.stockid,
								stockmaster.description,
								stockmaster.units
						FROM stockmaster INNER JOIN stockcategory
						ON stockmaster.categoryid=stockcategory.categoryid
						WHERE stockmaster.mbflag<>'D'
						AND stockmaster.mbflag<>'A'
						AND stockmaster.mbflag<>'K'
						AND stockmaster.mbflag<>'G'
						AND stockmaster.discontinued<>1
						AND stockmaster.description " . LIKE . " '" . $SearchString . "'
						AND stockmaster.categoryid='" . $_POST['StockCat'] . "'
						ORDER BY stockmaster.stockid
						LIMIT " . $_SESSION['DisplayRecordsMax'];
			}
		}

	} //$_POST['Keywords']
	elseif ($_POST['StockCode']) {
		$_POST['StockCode'] = '%' . $_POST['StockCode'] . '%';

		if ($_POST['StockCat'] == 'All') {
			if ($_POST['SupplierItemsOnly'] == 'on') {
				$sql = "SELECT stockmaster.stockid,
								stockmaster.description,
								stockmaster.units
						FROM stockmaster INNER JOIN stockcategory
						ON stockmaster.categoryid=stockcategory.categoryid
						INNER JOIN purchdata
						ON stockmaster.stockid=purchdata.stockid
						WHERE stockmaster.mbflag<>'D'
						AND stockmaster.mbflag<>'A'
						AND stockmaster.mbflag<>'K'
						AND stockmaster.mbflag<>'G'
						AND purchdata.supplierno='" . $_SESSION['PO' . $identifier]->SupplierID . "'
						AND stockmaster.discontinued<>1
						AND stockmaster.stockid " . LIKE . " '" . $_POST['StockCode'] . "'
						ORDER BY stockmaster.stockid
						LIMIT " . $_SESSION['DisplayRecordsMax'];
			} //$_POST['SupplierItemsOnly'] == 'on'
			else {
				$sql = "SELECT stockmaster.stockid,
							stockmaster.description,
							stockmaster.units
					FROM stockmaster INNER JOIN stockcategory
					ON stockmaster.categoryid=stockcategory.categoryid
					WHERE stockmaster.mbflag<>'D'
					AND stockmaster.mbflag<>'A'
					AND stockmaster.mbflag<>'K'
					AND stockmaster.mbflag<>'G'
					AND stockmaster.discontinued<>1
					AND stockmaster.stockid " . LIKE . " '" . $_POST['StockCode'] . "'
					ORDER BY stockmaster.stockid
					LIMIT " . $_SESSION['DisplayRecordsMax'];
			}
		} //$_POST['StockCat'] == 'All'
		else { //for a specific stock category and LIKE stock code
			if ($_POST['SupplierItemsOnly'] == 'on') {
				$sql = "SELECT stockmaster.stockid,
								stockmaster.description,
								stockmaster.units
						FROM stockmaster INNER JOIN stockcategory
						ON stockmaster.categoryid=stockcategory.categoryid
						INNER JOIN purchdata
						ON stockmaster.stockid=purchdata.stockid
						WHERE stockmaster.mbflag<>'D'
						AND stockmaster.mbflag<>'A'
						AND stockmaster.mbflag<>'K'
						AND stockmaster.mbflag<>'G'
						AND purchdata.supplierno='" . $_SESSION['PO' . $identifier]->SupplierID . "'
						AND stockmaster.discontinued<>1
						AND stockmaster.stockid " . LIKE . " '" . $_POST['StockCode'] . "'
						AND stockmaster.categoryid='" . $_POST['StockCat'] . "'
						ORDER BY stockmaster.stockid
						LIMIT " . $_SESSION['DisplayRecordsMax'];
			} //$_POST['SupplierItemsOnly'] == 'on'
			else {
				$sql = "SELECT stockmaster.stockid,
							stockmaster.description,
							stockmaster.units
					FROM stockmaster INNER JOIN stockcategory
					ON stockmaster.categoryid=stockcategory.categoryid
					WHERE stockmaster.mbflag<>'D'
					AND stockmaster.mbflag<>'A'
					AND stockmaster.mbflag<>'K'
					AND stockmaster.mbflag<>'G'
					AND stockmaster.discontinued<>1
					AND stockmaster.stockid " . LIKE . " '" . $_POST['StockCode'] . "'
					AND stockmaster.categoryid='" . $_POST['StockCat'] . "'
					ORDER BY stockmaster.stockid
					LIMIT " . $_SESSION['DisplayRecordsMax'];
			}
		}

	} //$_POST['StockCode']
	else {
		if ($_POST['StockCat'] == 'All') {
			if (isset($_POST['SupplierItemsOnly'])) {
				$sql = "SELECT stockmaster.stockid,
								stockmaster.description,
								stockmaster.units
						FROM stockmaster INNER JOIN stockcategory
						ON stockmaster.categoryid=stockcategory.categoryid
						INNER JOIN purchdata
						ON stockmaster.stockid=purchdata.stockid
						WHERE stockmaster.mbflag<>'D'
						AND stockmaster.mbflag<>'A'
						AND stockmaster.mbflag<>'K'
						AND stockmaster.mbflag<>'G'
						AND purchdata.supplierno='" . $_SESSION['PO' . $identifier]->SupplierID . "'
						AND stockmaster.discontinued<>1
						ORDER BY stockmaster.stockid
						LIMIT " . $_SESSION['DisplayRecordsMax'];
			} //isset($_POST['SupplierItemsOnly'])
			else {
				$sql = "SELECT stockmaster.stockid,
							stockmaster.description,
							stockmaster.units
					FROM stockmaster INNER JOIN stockcategory
					ON stockmaster.categoryid=stockcategory.categoryid
					WHERE stockmaster.mbflag<>'D'
					AND stockmaster.mbflag<>'A'
					AND stockmaster.mbflag<>'K'
					AND stockmaster.mbflag<>'G'
					AND stockmaster.discontinued<>1
					ORDER BY stockmaster.stockid
					LIMIT " . $_SESSION['DisplayRecordsMax'];
			}
		} //$_POST['StockCat'] == 'All'
		else { // for a specific stock category
			if ($_POST['SupplierItemsOnly'] == 'on') {
				$sql = "SELECT stockmaster.stockid,
								stockmaster.description,
								stockmaster.units
						FROM stockmaster INNER JOIN stockcategory
						ON stockmaster.categoryid=stockcategory.categoryid
						INNER JOIN purchdata
						ON stockmaster.stockid=purchdata.stockid
						WHERE stockmaster.mbflag<>'D'
						AND stockmaster.mbflag<>'A'
						AND stockmaster.mbflag<>'K'
						AND stockmaster.mbflag<>'G'
						AND purchdata.supplierno='" . $_SESSION['PO' . $identifier]->SupplierID . "'
						AND stockmaster.discontinued<>1
						AND stockmaster.categoryid='" . $_POST['StockCat'] . "'
						ORDER BY stockmaster.stockid
						LIMIT " . $_SESSION['DisplayRecordsMax'];
			} //$_POST['SupplierItemsOnly'] == 'on'
			else {
				$sql = "SELECT stockmaster.stockid,
							stockmaster.description,
							stockmaster.units
					FROM stockmaster INNER JOIN stockcategory
					ON stockmaster.categoryid=stockcategory.categoryid
					WHERE stockmaster.mbflag<>'D'
					AND stockmaster.mbflag<>'A'
					AND stockmaster.mbflag<>'K'
					AND stockmaster.mbflag<>'G'
					AND stockmaster.discontinued<>1
					AND stockmaster.categoryid='" . $_POST['StockCat'] . "'
					ORDER BY stockmaster.stockid
					LIMIT " . $_SESSION['DisplayRecordsMax'];
			}
		}
	}

	$ErrMsg = _('There is a problem selecting the part records to display because');
	$DbgMsg = _('The SQL statement that failed was');
	$SearchResult = DB_query($sql, $db, $ErrMsg, $DbgMsg);

	if (DB_num_rows($SearchResult) == 0 and $debug == 1) {
		prnMsg(_('There are no products to display matching the criteria provided'), 'warn');
	} //DB_num_rows($SearchResult) == 0 and $debug == 1
	if (DB_num_rows($SearchResult) == 1) {
		$myrow = DB_fetch_array($SearchResult);
		$_GET['NewItem'] = $myrow['stockid'];
		DB_data_seek($SearchResult, 0);
	} //DB_num_rows($SearchResult) == 1

} //end of if search

if (!isset($_GET['Edit'])) {
	$sql = "SELECT categoryid,
				categorydescription
			FROM stockcategory
			WHERE stocktype<>'L'
			AND stocktype<>'D'
			ORDER BY categorydescription";
	$ErrMsg = _('The supplier category details could not be retrieved because');
	$DbgMsg = _('The SQL used to retrieve the category details but failed was');
	$result1 = DB_query($sql, $db, $ErrMsg, $DbgMsg);

	echo '<table class="selection">
			<tr>
				<th colspan="3"><h3>' . _('Search For Stock Items') . ':</h3></th>';

	echo '</tr>
			<tr><td>' . _('Item Category') . ': <select minlength="0" name="StockCat">';

	echo '<option selected="selected" value="All">' . _('All') . '</option>';

	while ($myrow1 = DB_fetch_array($result1)) {
		if (isset($_POST['StockCat']) and $_POST['StockCat'] == $myrow1['categoryid']) {
			echo '<option selected="selected" value="' . $myrow1['categoryid'] . '">' . $myrow1['categorydescription'] . '</option>';
		} //isset($_POST['StockCat']) and $_POST['StockCat'] == $myrow1['categoryid']
		else {
			echo '<option value="' . $myrow1['categoryid'] . '">' . $myrow1['categorydescription'] . '</option>';
		}
	} //$myrow1 = DB_fetch_array($result1)

	unset($_POST['Keywords']);
	unset($_POST['StockCode']);

	if (!isset($_POST['Keywords'])) {
		$_POST['Keywords'] = '';
	} //!isset($_POST['Keywords'])

	if (!isset($_POST['StockCode'])) {
		$_POST['StockCode'] = '';
	} //!isset($_POST['StockCode'])

	echo '</select></td>
		<td>' . _('Enter text extracts in the description') . ':</td>
		<td><input type="text" name="Keywords" size="20" minlength="0" maxlength="25" value="' . $_POST['Keywords'] . '" /></td></tr>
		<tr><td>' . _('Only items defined as from this Supplier') . ' <input type="checkbox" name="SupplierItemsOnly" ';
	if (isset($_POST['SupplierItemsOnly']) and $_POST['SupplierItemsOnly'] == 'on') {
		echo 'checked';
	} //isset($_POST['SupplierItemsOnly']) and $_POST['SupplierItemsOnly'] == 'on'
	echo ' /></td>
			<td><b>' . _('OR') . ' </b>' . _('Enter extract of the Stock Code') . ':</td>
			<td><input type="text" name="StockCode" size="15" minlength="0" maxlength="18" value="' . $_POST['StockCode'] . '" /></td>
		</tr>
		<tr>
			<td></td>
			<td><b>' . _('OR') . ' </b><a target="_blank" href="' . $RootPath . '/Stocks.php">' . _('Insert New Item') . '</a></td>
		</tr>
		</table>

		<div class="centre"><input type="submit" name="Search" value="' . _('Search Now') . '" />
		<input type="submit" name="NonStockOrder" value="' . _('Order a non stock item') . '" />
		</div>';

	$PartsDisplayed = 0;
} //!isset($_GET['Edit'])

if (isset($SearchResult)) {
	echo '<table cellpadding="1" class="selection">
			<tr>
				<th class="SortableColumn">' . _('Code') . '</th>
				<th class="SortableColumn">' . _('Description') . '</th>
				<th>' . _('Our Units') . '</th>
				<th>' . _('Conversion') . '<br />' . _('Factor') . '</th>
				<th>' . _('Supplier/Order') . '<br />' . _('Units') . '</th>
				<th colspan="2"><a href="#end">' . _('Go to end of list') . '</a></th>
			</tr>';

	$k = 0; //row colour counter
	$j = 0;

	while ($myrow = DB_fetch_array($SearchResult)) {
		if ($k == 1) {
			echo '<tr class="EvenTableRows">';
			$k = 0;
		} //$k == 1
		else {
			echo '<tr class="OddTableRows">';
			$k = 1;
		}

		$FileName = $myrow['stockid'] . '.jpg';
		if (file_exists($_SESSION['part_pics_dir'] . '/' . $FileName)) {
			$ImageSource = '<img src="' . $RootPath . '/' . $_SESSION['part_pics_dir'] . '/' . $myrow['stockid'] . '.jpg" width="50" height="50" />';
		} //file_exists($_SESSION['part_pics_dir'] . '/' . $FileName)
		else {
			$ImageSource = '<i>' . _('No Image') . '</i>';
		}

		/*Get conversion factor and supplier units if any */
		$sql = "SELECT purchdata.conversionfactor,
						purchdata.suppliersuom
					FROM purchdata
					WHERE purchdata.supplierno='" . $_SESSION['PO' . $identifier]->SupplierID . "'
					AND purchdata.stockid='" . $myrow['stockid'] . "'";
		$ErrMsg = _('Could not retrieve the purchasing data for the item');
		$PurchDataResult = DB_query($sql, $db, $ErrMsg);

		if (DB_num_rows($PurchDataResult) > 0) {
			$PurchDataRow = DB_fetch_array($PurchDataResult);
			$OrderUnits = $PurchDataRow['suppliersuom'];
			$ConversionFactor = locale_number_format($PurchDataRow['conversionfactor'], 'Variable');
		} //DB_num_rows($PurchDataResult) > 0
		else {
			$OrderUnits = $myrow['units'];
			$ConversionFactor = 1;
		}
		echo '<td>' . $myrow['stockid'] . '</td>
			<td>' . $myrow['description'] . '</td>
			<td>' . $myrow['units'] . '</td>
			<td class="number">' . $ConversionFactor . '</td>
			<td>' . $OrderUnits . '</td>
			<td>' . $ImageSource . '</td>
			<td><input class="number" type="text" size="6" required="required" minlength="1" maxlength="11" value="0" name="NewQty' . $j . '" /></td>
			<input type="hidden" name="StockID' . $j . '" . value="' . $myrow['stockid'] . '" />
			</tr>';
		$j++;
		$PartsDisplayed++;
		//end of page full new headings if
	} //$myrow = DB_fetch_array($SearchResult)
	//end of while loop
	echo '</table>';
	echo '<input type="hidden" name="PO_ItemsResubmitFormValue" value="' . $_SESSION['PO_ItemsResubmitForm' . $identifier] . '" />';
	echo '<a name="end"></a><br /><div class="centre"><input type="submit" name="NewItem" value="' . _('Order some') . '" /></div>';
} //end if SearchResults to show

echo '</form>';
include('includes/footer.inc');
?>