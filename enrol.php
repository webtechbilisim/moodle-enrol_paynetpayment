<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Creates a paynet payment form and show it on course enrolment page
 *
 * This script creates a payment form for paynet
 * let user to pay and enrol to course.
 *
 * @package    enrol_paynetpayment
 * @copyright  2019 Dualcube Team
 * @copyright  2021 WebTech Bilişim
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Disable moodle specific debug messages and any errors in output,
// comment out when debugging or better look into error log!
defined('MOODLE_INTERNAL') || die();
global $CFG, $USER;

/**
 * Generate a random string, using a cryptographically secure 
 * pseudorandom number generator (random_int)
 *
 * This function uses type hints now (PHP 7+ only), but it was originally
 * written for PHP 5 as well.
 * 
 * For PHP 7, random_int is a PHP core function
 * For PHP 5.x, depends on https://github.com/paragonie/random_compat
 * 
 * @param int $length      How many characters do we want?
 * @param string $keyspace A string of all possible characters
 *                         to select from
 * @return string
 */
function random_str(
  int $length = 64,
  string $keyspace = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'
): string {
  if ($length < 1) {
    throw new \RangeException("Length must be a positive integer");
  }
  $pieces = [];
  $max = mb_strlen($keyspace, '8bit') - 1;
  for ($i = 0; $i < $length; ++$i) {
    $pieces[] = $keyspace[random_int(0, $max)];
  }
  return implode('', $pieces);
}



if ($this->get_config('sandboxmode')) {
  $PaynetJsURL = "https://pts-pj.paynet.com.tr/public/js/paynet.min.js";
} else {
  $PaynetJsURL="https://pj.paynet.com.tr/public/js/paynet.min.js";
}

// If Logo Exists
$LogoInit = curl_init($OUTPUT->get_logo_url()->out(true));
curl_setopt($LogoInit, CURLOPT_RETURNTRANSFER, TRUE);
$LogoHTTPCode = curl_getinfo($LogoInit, CURLINFO_HTTP_CODE);
if($LogoHTTPCode!=404)
{
	$LogoURL = "data-image='".$OUTPUT->get_logo_url()->out(true)."'";
}
curl_close($LogoInit);
// END - If Logo Exists 

// Installment Check
if($this->get_config('installment'))
{
	$InstallmentStatus = 0;
	$InstallmentStatusBool = "false";
}
else
{
	$InstallmentStatus = 1;
	$InstallmentStatusBool = "true";
}
// END - Installment Check

?>
<style>
	.paynetj-button{
		cursor:pointer;
		color:#fff;
		border-color:#357a32;
		background-color:#357a32;
		display:block;
		width:100%;
	}
</style>
<form action = "<?=$CFG->wwwroot . "/enrol/paynetpayment/callback.php"?>" method="POST" > 
	<input type="hidden" name="reference_code" value="<?=random_str() . "-" . $USER->id . "-" . $course->id . "-" . $instance->id;?>">
	<input type="hidden" name="amount" value="<?=$cost*100;?>">
	<input type="hidden" name="no_installment" value="<?=$InstallmentStatusBool;?>">
 	<script 
                class="paynet-button" 
                type="text/javascript" 
                src="<?=$PaynetJsURL?>" 
		  		<?=$LogoURL;?>
		  		data-name="<?=$coursefullname;?>"
				data-reference_no="<?=random_str() . "-" . $USER->id . "-" . $course->id . "-" . $instance->id;?>"
		  		data-description="<?=$coursefullname." ".get_string('payment_description', 'enrol_paynetpayment');?>"
                data-key="<?=$this->get_config('publishablekey');?>" 
                data-amount="<?=$cost*100;?>"                 
				data-no_instalment="<?=$InstallmentStatusBool?>"
                data-button_label="<?=get_string('join_course', 'enrol_paynetpayment');?>">
	 </script>
	<script>
		 
	</script>
	 
</form>


