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
 * Initially developped for :
 * Université de Cergy-Pontoise
 * 33, boulevard du Port
 * 95011 Cergy-Pontoise cedex
 * FRANCE
 *
 * Create the accounts of students and teachers based on xml files and fill tables used for statistics.
 *
 * @package   local_usercreation
 * @copyright 2017 Laurent Guillet <laurent.guillet@u-cergy.fr>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * File : createteachers.php
 * Create the teachers account
 */

namespace local_usercreation\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot .'/course/lib.php');
require_once($CFG->libdir .'/filelib.php');
require_once($CFG->libdir .'/accesslib.php');

class createteachers extends \core\task\scheduled_task {
    public function get_name() {

        return get_string('createteachers', 'local_usercreation');
    }

    public function execute() {

        global $DB;

        $context = get_context_instance(CONTEXT_SYSTEM);

        /* ON CHARGE LE XML */

        $xmldoc = new \DOMDocument();
        $xmldoc->load('/home/referentiel/DOKEOS_Enseignants_Affectations.xml');
        $xpathvar = new \Domxpath($xmldoc);

        $listteachers = $xpathvar->query('//Teacher');
        foreach($listteachers as $teacher){

            if($teacheruid = $teacher->getAttribute('StaffUID')){

                $affectations = $teacher->childNodes;

                foreach ($affectations as $affectation) {

                    if ($affectation->nodeType !== 1 ) {
                        continue;
                    }

                    $position = $affectation->getAttribute('Position');

                    if ($position != 'Sursitaire' && $position != "") {

                        echo 'position : '.$position.'<br>';

                        if ($DB->record_exists('user',
                                array('username' => $teacher->getAttribute('StaffUID')))) {


                            $teacherdata = $DB->get_record('user',
                                    array('username' => $teacher->getAttribute('StaffUID')));

                            $coursecreatorrole = $DB->get_record('role',
                                    array('shortname' => 'coursecreator'))->id;

                            if (!$DB->record_exists('role_assignments',
                                    array('roleid' => $coursecreatorrole, 'contextid' => $context->id ,
                                        'userid' => $teacherdata->id))) {

                                role_assign($coursecreatorrole, $teacherdata->id, $context->id);
                            }

                            $teacherdata->firstname = ucwords(strtolower(
                                    $teacher->getAttribute('StaffFirstName')));
                            $teacherdata->lastname = ucwords(strtolower(
                                    $teacher->getAttribute('StaffCommonName')));
                            $teacherdata->email = ucwords(strtolower(
                                    $teacher->getAttribute('StaffEmail')));

                            $DB->update_record('user', $teacherdata);
                        } else {

                            $teacherdata = new \StdClass();
                            $teacherdata->auth = 'cas';
                            $teacherdata->confirmed = 1;
                            $teacherdata->mnethostid = 1;
                            $teacherdata->email = $teacher->getAttribute('StaffEmail');
                            $teacherdata->username = $teacher->getAttribute('StaffUID');
                            $teacherdata->password = '';
                            $teacherdata->lastname = ucwords(strtolower($teacher->getAttribute('StaffCommonName')));
                            $teacherdata->firstname = ucwords(strtolower($teacher->getAttribute('StaffFirstName')));
                            $teacherdata->timecreated = time();
                            $teacherdata->timemodified = time();
                            $teacherdata->lang = 'fr';
                            $teacherdata->id = $DB->insert_record('user', $teacherdata);

                            $coursecreatorrole = $DB->get_record('role',
                                    array('shortname' => 'coursecreator'))->id;

                            role_assign($coursecreatorrole, $teacherdata->id, $context->id);
                        }
                    }
                }

                // Ici, gérer local_ufrteacher et local_teachertype

                if ($DB->record_exists('user', array('username' => $teacher->getAttribute('StaffUID')))) {

                    $teacherdata = $DB->get_record('user',
                            array('username' => $teacher->getAttribute('StaffUID')));

                    foreach ($affectations as $affectation) {

                        if ($affectation->nodeType !== 1 ) {
                            continue;
                        }

                        $codestructure = $affectation->getAttribute('CodeStructure');
                        if (isset($codestructure)) {

                            $ufrcode = substr($codestructure, 0, 1);
                            if (!$DB->record_exists('local_ufrteacher',
                                    array('userid' => $teacherdata->id, 'ufrcode' => $ufrcode))) {

                                $ufrteacher = array();
                                $ufrteacher['userid'] = $teacherdata->id;
                                $ufrteacher['ufrcode'] = $ufrcode;
                                $DB->insert_record('local_ufrteacher', $ufrteacher);
                                if ($DB->record_exists('local_ufrteacher',
                                    array('userid' => $teacherdata->id, 'ufrcode' => '-1'))) {
                                    $DB->delete_record('local_ufrteacher',
                                            array('userid' => $teacherdata->id, 'ufrcode' => '-1'));
                                }
                            }
                        }
                    }

                    if (!$DB->record_exists('local_ufrteacher', array('userid' => $teacherdata->id))) {

                        $ufrteacher = array();
                        $ufrteacher['userid'] = $teacherdata->id;
                        $ufrteacher['ufrcode'] = '-1';
                        $DB->insert_record('local_ufrteacher', $ufrteacher);
                    }

                    if ($teacher->getAttribute('LC_CORPS') != null && $teacher->getAttribute('LC_CORPS') != "") {

                        $sqlrecordexistsinfodata = "SELECT * FROM {local_teachertype} WHERE "
                                . "userid = ? AND typeteacher LIKE ?";

                        if (!$DB->record_exists_sql($sqlrecordexistsinfodata,
                                    array($teacherdata->id, $teacher->getAttribute('LC_CORPS')))) {

                            $typeprofdata = array();
                            $typeprofdata['userid'] = $teacherdata->id;
                            $typeprofdata['typeteacher'] = $teacher->getAttribute('LC_CORPS');
                            $DB->insert_record('local_teachertype', $typeprofdata);

                            if ($DB->record_exists_sql($sqlrecordexistsinfodata,
                                array($teacherdata->id, "Non indiqué"))) {

                                $DB->delete_records('local_teachertype',
                                        array('userid' => $teacherdata->id, 'typeteacher' => "Non indiqué"));
                            }
                        }
                    }

                    if (!$DB->record_exists('local_teachertype', array('userid' => $teacherdata->id))) {

                        $typeprofdata = array();
                        $typeprofdata['userid'] = $teacherdata->id;
                        $typeprofdata['typeteacher'] = "Non indiqué";
                        $DB->insert_record('local_teachertype', $typeprofdata);
                    }
                }
            }
        }
    }
}