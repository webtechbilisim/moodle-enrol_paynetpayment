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
 * Listens payment result callback from paynet
 *
 * @package    enrol_paynetpayment
 * @copyright  2019 Dualcube Team
 * @copyright  2021 WebTech Bilişim
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Disable moodle specific debug messages and any errors in output,
// comment out when debugging or better look into error log!
define('NO_DEBUG_DISPLAY', true);

require("../../config.php");
require_once("lib.php");
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->libdir . '/filelib.php');

require_login();


/**
 * Send payment error message to the admin.
 *
 * @param string $subject
 * @param stdClass $data
 */
function message_paynetpayment_error_to_admin($subject, $data)
{
    die($subject);
    $admin = get_admin();
    $site = get_site();

    $message = "$site->fullname:  Transaction failed.\n\n$subject\n\n";

    foreach ($data as $key => $value) {
        $message .= s($key) . " => " . s($value) . "\n";
    }

    $subject = "Paynet PAYMENT ERROR: " . $subject;
    $fullmessage = $message;
    $fullmessagehtml = html_to_text('<p>' . $message . '</p>');

    // Send test email.
    ob_start();
    $success = email_to_user($admin, $admin, $subject, $fullmessage, $fullmessagehtml);
    $smtplog = ob_get_contents();
    ob_end_clean();
}

$data = [];

//  Paynet Payment START

$plugin = enrol_get_plugin('paynetpayment');

$secret_key = $plugin->get_config('secretkey');

if ($plugin->get_config('sandboxmode')) {
  $PaynetPostURL = "https://pts-api.paynet.com.tr/v1/transaction/charge";
} else {
  $PaynetPostURL = "https://api.paynet.com.tr/v1/transaction/charge";
}
		
$session_id = $_REQUEST["session_id"];
$token_id 	= $_REQUEST["token_id"];
			
// GET Payment Parameters
$parts = explode("-", $_POST['reference_code']);
$userId = $parts[1];
$courseId = $parts[2];
$instanceId = $parts[3];


$params = array(
			'session_id' => $session_id,
			'token_id' => $token_id,
			'transaction_type' => 1,	
			'amount' => $_POST['amount'],
			'add_comission_amount' => false,
			'ratio_code' => '',
			'installments' => '',
			'no_instalment' => false,
			'tds_required' => true
);
			
$options = array(
			'http' => array(
					'header'  =>"Accept: application/json; charset=UTF-8\r\n".
					"Content-type: application/json; charset=UTF-8\r\n".
					"Authorization: Basic ".$secret_key,
					'method'  => 'POST',
					'content' => json_encode($params),
					'ignore_errors' => true
					),
			);
$context  = stream_context_create($options);
$result = json_decode(@file_get_contents($PaynetPostURL, false, $context));

if (!$user = $DB->get_record("user", array("id" => $userId))) {
message_paynetpayment_error_to_admin("Not a valid user id", $data);
redirect($CFG->wwwroot);
}
// Payment Pricing Control

// Course Control
if (!$course = $DB->get_record("course", array("id" => $courseId))) {
message_paynetpayment_error_to_admin("Not a valid course id", $data);
redirect($CFG->wwwroot);
}

// Context Control
if (!$context = context_course::instance(
$course->id,
IGNORE_MISSING
)) {
message_paynetpayment_error_to_admin("Not a valid context id", $data);
redirect($CFG->wwwroot);
}

$PAGE->set_context($context);
// Instance Control
if (!$plugininstance = $DB->get_record("enrol", array("id" => $instanceId, "status" => 0))) {
message_paynetpayment_error_to_admin("Not a valid instance id", $data);
redirect($CFG->wwwroot);
}

// CourseID Control
if ($courseId != $plugininstance->courseid) {
message_paynetpayment_error_to_admin("Course Id does not match to the course settings, received: " . $data->courseid, $data);
redirect($CFG->wwwroot);
}

// ALL CLEAR !

if($result->is_succeed == false)
{
  echo "<p>" . $result->paynet_error_message . " Code:" . $result->paynet_error_id . "</p>";
}
else
{
	$paymentData = new stdClass;
	$paymentData->payment_id = $result->id;
	$paymentData->course_id = $courseId;
	$paymentData->user_id = $userId;
	$paymentData->instance_id = $instanceId;
	$paymentData->price = $result->amount;
	$paymentData->paid_price = $result->amount;
	$paymentData->currency = $result->currency;
	$paymentData->payment_status = $result->is_succeed;
	$paymentData->pending_reason = $result->message;
	$paymentData->reason_code = $result->code;
	$paymentData->time_updated = time();

	$DB->insert_record("enrol_paynetpayment", $paymentData);
	
	// Period Enrol
	if ($plugininstance->enrolperiod) {
		$timestart = time();
		$timeend   = $timestart + $plugininstance->enrolperiod;
	} else {
		$timestart = 0;
		$timeend   = 0;
	}

	// Enrol user.
	$plugin->enrol_user($plugininstance, $user->id, $plugininstance->roleid, $timestart, $timeend);

		// Pass $view=true to filter hidden caps if the user cannot see them.
        if ($users = get_users_by_capability(
            $context,
            'moodle/course:update',
            'u.*',
            'u.id ASC',
            '',
            '',
            '',
            '',
            false,
            true
        )) {
            $users = sort_by_roleassignment_authority($users, $context);
            $teacher = array_shift($users);
        } else {
            $teacher = false;
        }
        $mailstudents = $plugin->get_config('mailstudents');
        $mailteachers = $plugin->get_config('mailteachers');
        $mailadmins   = $plugin->get_config('mailadmins');
        $shortname = format_string($course->shortname, true, array('context' => $context));

        $coursecontext = context_course::instance($course->id);

        if (!empty($mailstudents)) {
            $a = new stdClass();
            $a->coursename = format_string($course->fullname, true, array('context' => $coursecontext));
            $a->profileurl = "$CFG->wwwroot/user/view.php?id=$user->id";

            $userfrom = empty($teacher) ? core_user::get_support_user() : $teacher;
            $subject = get_string("enrolmentnew", 'enrol', $shortname);
            $fullmessage = get_string('welcometocoursetext', '', $a);
            $fullmessagehtml = html_to_text('<p>' . get_string('welcometocoursetext', '', $a) . '</p>');

            // Send test email.
            ob_start();
            $success = email_to_user($user, $userfrom, $subject, $fullmessage, $fullmessagehtml);
            $smtplog = ob_get_contents();
            ob_end_clean();
        }

        if (!empty($mailteachers) && !empty($teacher)) {
            $a->course = format_string($course->fullname, true, array('context' => $coursecontext));
            $a->user = fullname($user);

            $subject = get_string("enrolmentnew", 'enrol', $shortname);
            $fullmessage = get_string('enrolmentnewuser', 'enrol', $a);
            $fullmessagehtml = html_to_text('<p>' . get_string('enrolmentnewuser', 'enrol', $a) . '</p>');

            // Send test email.
            ob_start();
            $success = email_to_user($teacher, $user, $subject, $fullmessage, $fullmessagehtml);
            $smtplog = ob_get_contents();
            ob_end_clean();
        }

        if (!empty($mailadmins)) {
            $a->course = format_string($course->fullname, true, array('context' => $coursecontext));
            $a->user = fullname($user);
            $admins = get_admins();
            foreach ($admins as $admin) {
                $subject = get_string("enrolmentnew", 'enrol', $shortname);
                $fullmessage = get_string('enrolmentnewuser', 'enrol', $a);
                $fullmessagehtml = html_to_text('<p>' . get_string('enrolmentnewuser', 'enrol', $a) . '</p>');

                // Send test email.
                ob_start();
                $success = email_to_user($admin, $user, $subject, $fullmessage, $fullmessagehtml);
                $smtplog = ob_get_contents();
                ob_end_clean();
            }
        }

        $destination = "$CFG->wwwroot/course/view.php?id=$course->id";

        $fullname = format_string($course->fullname, true, array('context' => $context));

        if (is_enrolled($context, null, '', true)) { // TODO: use real PayNet check.
            redirect($destination, get_string('paymentthanks', '', $fullname));
        } else {   // Somehow they aren't enrolled yet!
            $PAGE->set_url($destination);
            echo $OUTPUT->header();
            $a = new stdClass();
            $a->teacher = get_string('defaultcourseteacher');
            $a->fullname = $fullname;
            notice(get_string('paymentsorry', '', $a), $destination);
        }
}


?>