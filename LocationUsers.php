<?php

include('includes/session.inc');
$Title = _('Maintenance Of Location Authorised Users');
include('includes/header.inc');

echo '<p class="page_title_text"><img src="' . $RootPath . '/css/' . $Theme . '/images/money_add.png" title="' . _('Location Authorised Users') . '" alt="" />' . ' ' . $Title . '</p>';

if (isset($_POST['SelectedUser'])) {
	$SelectedUser = mb_strtoupper($_POST['SelectedUser']);
} elseif (isset($_GET['SelectedUser'])) {
	$SelectedUser = mb_strtoupper($_GET['SelectedUser']);
} else {
	$SelectedUser = '';
}

if (isset($_POST['SelectedLocation'])) {
	$SelectedLocation = mb_strtoupper($_POST['SelectedLocation']);
} elseif (isset($_GET['SelectedLocation'])) {
	$SelectedLocation = mb_strtoupper($_GET['SelectedLocation']);
}

if (isset($_POST['Cancel'])) {
	unset($SelectedLocation);
	unset($SelectedUser);
}

if (isset($_POST['Process'])) {
	if ($_POST['SelectedLocation'] == '') {
		echo prnMsg(_('You have not selected any Location'), 'error');
		echo '<br />';
		unset($SelectedLocation);
		unset($_POST['SelectedLocation']);
	}
}

if (isset($_POST['submit'])) {

	$InputError = 0;

	if ($_POST['SelectedUser'] == '') {
		$InputError = 1;
		echo prnMsg(_('You have not selected an user to be authorised to use this Location'), 'error');
		echo '<br />';
		unset($SelectedLocation);
	}

	if ($InputError != 1) {

		// First check the user is not being duplicated

		$CheckSql = "SELECT count(*)
			     FROM locationusers
			     WHERE loccode= '" . $_POST['SelectedLocation'] . "'
				 AND userid = '" . $_POST['SelectedUser'] . "'";

		$CheckResult = DB_query($CheckSql);
		$CheckRow = DB_fetch_row($CheckResult);

		if ($CheckRow[0] > 0) {
			$InputError = 1;
			prnMsg(_('The user') . ' ' . $_POST['SelectedUser'] . ' ' . _('already authorised to use this Location'), 'error');
		} else {
			// Add new record on submit
			$SQL = "INSERT INTO locationusers (loccode,
												userid,
												canview,
												canupd)
										VALUES ('" . $_POST['SelectedLocation'] . "',
												'" . $_POST['SelectedUser'] . "',
												'1',
												'1')";

			$msg = _('User') . ': ' . $_POST['SelectedUser'] . ' ' . _('authorisation to use') . ' ' . $_POST['SelectedLocation'] . ' ' . _('Location has been changed');
			$Result = DB_query($SQL);
			prnMsg($msg, 'success');
			unset($_POST['SelectedUser']);
		}
	}
} elseif (isset($_GET['delete'])) {
	$SQL = "DELETE FROM locationusers
		WHERE loccode='" . $SelectedLocation . "'
		AND userid='" . $SelectedUser . "'";

	$ErrMsg = _('The Location user record could not be deleted because');
	$Result = DB_query($SQL, $ErrMsg);
	prnMsg(_('User') . ' ' . $SelectedUser . ' ' . _('has been un-authorised to use') . ' ' . $SelectedLocation . ' ' . _('Location'), 'success');
	unset($_GET['delete']);
} elseif (isset($_GET['toggleupd'])) {
	$SQL = "UPDATE locationusers
		SET canupd='" . $_GET['toggleupd'] . "'
		WHERE loccode='" . $SelectedLocation . "'
		AND userid='" . $SelectedUser . "'";

	$ErrMsg = _('The Location user record could not be deleted because');
	$Result = DB_query($SQL, $ErrMsg);
	prnMsg(_('User') . ' ' . $SelectedUser . ' ' . _('has been un-authorised to update') . ' ' . $SelectedLocation . ' ' . _('Location'), 'success');
	unset($_GET['toggleupd']);
}

if (!isset($SelectedLocation)) {

	/* It could still be the second time the page has been run and a record has been selected for modification - SelectedUser will exist because it was sent with the new call. If its the first time the page has been displayed with no parameters
	then none of the above are true. These will call the same page again and allow update/input or deletion of the records*/
	echo '<form method="post" action="' . htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8') . '">';
	echo '<input type="hidden" name="FormID" value="' . $_SESSION['FormID'] . '" />
			<table class="selection">
			<tr>
				<td>' . _('Select Location') . ':</td>
				<td><select name="SelectedLocation">';

	$SQL = "SELECT loccode,
					locationname
			FROM locations";

	$Result = DB_query($SQL);
	echo '<option value="">' . _('Not Yet Selected') . '</option>';
	while ($MyRow = DB_fetch_array($Result)) {
		if (isset($SelectedLocation) and $MyRow['loccode'] == $SelectedLocation) {
			echo '<option selected="selected" value="';
		} else {
			echo '<option value="';
		}
		echo $MyRow['loccode'] . '">' . $MyRow['loccode'] . ' - ' . $MyRow['locationname'] . '</option>';

	} //end while loop

	echo '</select></td></tr>';

	echo '</table>'; // close main table
	DB_free_result($Result);

	echo '<div class="centre">
			<input type="submit" name="Process" value="' . _('Accept') . '" />
			<input type="submit" name="Cancel" value="' . _('Cancel') . '" />
		</div>';

	echo '</form>';

}

//end of ifs and buts!
if (isset($_POST['process']) or isset($SelectedLocation)) {
	$SQLName = "SELECT locationname
			FROM locations
			WHERE loccode='" . $SelectedLocation . "'";
	$Result = DB_query($SQLName);
	$MyRow = DB_fetch_array($Result);
	$SelectedBankName = $MyRow['locationname'];

	echo '<div class="centre"><a href="' . htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8') . '">' . _('Authorised users for') . ' ' . $SelectedBankName . ' ' . _('Location') . '</a></div>';
	echo '<form method="post" action="' . htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8') . '">';
	echo '<input type="hidden" name="FormID" value="' . $_SESSION['FormID'] . '" />';

	echo '<input type="hidden" name="SelectedLocation" value="' . $SelectedLocation . '" />';

	$SQL = "SELECT locationusers.userid,
					canview,
					canupd,
					www_users.realname
			FROM locationusers INNER JOIN www_users
			ON locationusers.userid=www_users.userid
			WHERE locationusers.loccode='" . $SelectedLocation . "'
			ORDER BY locationusers.userid ASC";

	$Result = DB_query($SQL);

	echo '<table class="selection">';
	echo '<tr>
			<th colspan="6"><h3>' . _('Authorised users for Location') . ' ' . $SelectedBankName . '</h3></th>
		</tr>';
	echo '<tr>
			<th>' . _('User Code') . '</th>
			<th>' . _('User Name') . '</th>
			<th>' . _('View') . '</th>
			<th>' . _('Update') . '</th>
		</tr>';

	$k = 0; //row colour counter

	while ($MyRow = DB_fetch_array($Result)) {
		if ($k == 1) {
			echo '<tr class="EvenTableRows">';
			$k = 0;
		} else {
			echo '<tr class="OddTableRows">';
			$k = 1;
		}

		if ($MyRow['canupd'] == 1) {
			$ToggleText = '<td><a href="%s?SelectedUser=%s&amp;toggleupd=0&amp;SelectedLocation=' . $SelectedLocation . '" onclick="return confirm(\'' . _('Are you sure you wish to remove Update for this user?') . '\');">' . _('Remove Update') . '</a></td>';
		} else {
			$ToggleText = '<td><a href="%s?SelectedUser=%s&amp;toggleupd=1&amp;SelectedLocation=' . $SelectedLocation . '" onclick="return confirm(\'' . _('Are you sure you wish to add Update for this user?') . '\');">' . _('Add Update') . '</a></td>';
		}

		printf('<td>%s</td>
				<td>%s</td>
				<td>%s</td>
				<td>%s</td>' .
				$ToggleText . '
				<td><a href="%s?SelectedUser=%s&amp;delete=yes&amp;SelectedLocation=' . $SelectedLocation . '" onclick="return confirm(\'' . _('Are you sure you wish to un-authorise this user?') . '\');">' . _('Un-authorise') . '</a></td>
				</tr>',
				$MyRow['userid'],
				$MyRow['realname'],
				$MyRow['canview'],
				$MyRow['canupd'],
				htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'),
				$MyRow['userid'],
				htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'),
				$MyRow['userid']);
	}
	//END WHILE LIST LOOP
	echo '</table>';

	if (!isset($_GET['delete'])) {


		echo '<table  class="selection">'; //Main table

		echo '<tr>
				<td>' . _('Select User') . ':</td>
				<td><select name="SelectedUser">';

		$SQL = "SELECT userid,
						realname
				FROM www_users";

		$Result = DB_query($SQL);
		if (!isset($_POST['SelectedUser'])) {
			echo '<option selected="selected" value="">' . _('Not Yet Selected') . '</option>';
		}
		while ($MyRow = DB_fetch_array($Result)) {
			if (isset($_POST['SelectedUser']) and $MyRow['userid'] == $_POST['SelectedUser']) {
				echo '<option selected="selected" value="';
			} else {
				echo '<option value="';
			}
			echo $MyRow['userid'] . '">' . $MyRow['userid'] . ' - ' . $MyRow['realname'] . '</option>';

		} //end while loop

		echo '</select>
					</td>
				</tr>';

		echo '</table>'; // close main table
		DB_free_result($Result);

		echo '<div class="centre">
				<input type="submit" name="submit" value="' . _('Accept') . '" />
				<input type="submit" name="Cancel" value="' . _('Cancel') . '" />
			</div>';

		echo '</form>';

	} // end if user wish to delete
}

include('includes/footer.inc');
?>