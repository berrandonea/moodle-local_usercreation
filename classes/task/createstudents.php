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
 * File : createstudents.php
 * Create the students account
 */

namespace local_usercreation\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot .'/course/lib.php');
require_once($CFG->libdir .'/filelib.php');
require_once($CFG->libdir .'/accesslib.php');

class createstudents extends \core\task\scheduled_task {
    public function get_name() {

        return get_string('createstudents', 'local_usercreation');
    }

    public function execute() {

        global $DB;

        $context = get_context_instance(CONTEXT_SYSTEM);

        $xmldoc = new \DOMDocument();
        $xmldoc->load('/home/referentiel/DOKEOS_Etudiants_Inscriptions.xml');
        $xpathvar = new \Domxpath($xmldoc);
        $liststudents = $xpathvar->query('//Student');
        foreach($liststudents as $student) {

            $studentuid = $student->getAttribute('StudentUID');
            $email = $student->getAttribute('StudentEmail');
            $idnumber = $student->getAttribute('StudentETU');
            $lastname = ucwords(strtolower($student->getAttribute('StudentName')));
            $firstname = ucwords(strtolower($student->getAttribute('StudentFirstName')));

            $universityyears = $student->childNodes;

            echo 'studentuid = '.$studentuid.'<br>';

            if($studentuid){

                foreach ($universityyears as $universityyear) {

                    if ($universityyear->nodeType !== 1 ) {

                        continue;
                    }

                    //On regarde uniquement l'année en cours.

                    $year = $universityyear->getAttribute('AnneeUniv');

                    $configyear = get_config('local_usercreation', 'year');

                    if ($year == $configyear) {


                        //Si l'utilisateur est déjà dans la table user
                        if($DB->record_exists('user', array('username'=>$studentuid))) {

                            $studentdata = $DB->get_record('user', array('username'=>$studentuid));

                            $studentdata->firstname = $firstname;
                            $studentdata->lastname = $lastname;
                            $studentdata->idnumber = $idnumber;
                            $studentdata->email = $email;

                            $DB->update_record('user', $studentdata);

                        //Sinon
                        } else {

                            $user = new \StdClass();
                            $user->auth = 'cas';
                            $user->confirmed = 1;
                            $user->mnethostid = 1;
                            $user->email = $email;
                            $user->username = $studentuid;
                            $user->password = '';
                            $user->lastname = $lastname;
                            $user->firstname = $firstname;
                            $user->idnumber = $idnumber;
                            $user->timecreated = time();
                            $user->timemodified = time();
                            $user->lang = 'fr';
                            $user->id = $DB->insert_record('user', $user);
                            echo "Nouvel étudiant: $firstname $lastname ($studentuid, $idnumber)\n";

                            $userrole = $DB->get_record('role',
                                    array('shortname' => 'user'))->id;

                            if (!$DB->record_exists('role_assignments',
                                    array('roleid' => $userrole, 'contextid' => $context->id ,
                                        'userid' => $user->id))) {

                                role_assign($userrole, $user->id, $context->id);
                            }
                        }

                        //Pour chaque inscription de l'utilisateur en 2017
                        $listinscriptions = $universityyear->childNodes;

                        $liststudentufr = $DB->get_records('local_ufrstudent',
                                    array('userid' => $user->id));

                        $listtempstudentufr = array();

                        foreach ($liststudentufr as $studentufr) {

                            $tempstudentufr = new stdClass();
                            $tempstudentufr->ufrcode = $studentufr->ufrcode;
                            $tempstudentufr->stillexists = 0;

                            $listtempstudentufr[] = $tempstudentufr;
                        }

                        $liststudentvet = $DB->get_records('local_studentvet',
                                    array('userid' => $user->id));

                        $listtempstudentvet = array();

                        foreach ($liststudentvet as $studentvet) {

                            $tempstudentvet = new stdClass();
                            $tempstudentvet->categoryid = $studentvet->categoryid;
                            $tempstudentvet->stillexists = 0;

                            $listtempstudentvet[] = $tempstudentvet;
                        }

                        foreach ($listinscriptions as $inscription) {

                            if ($inscription->nodeType !== 1 ) {

                                continue;
                            }

                            $codeetape = $inscription->getAttribute('CodeEtape');
                            $codeetapeyear = "Y$year-$codeetape";
                            $ufrcode = substr($codeetape, 0, 1);
                            $ufrcodeyear = "Y$year-$ufrcode";

                            $nbufrstudent = $DB->count_records('local_ufrstudent',
                                    array('userid' => $user->id, 'ufrcode' => $ufrcodeyear));

                            //Si cette inscription de l'utilisateur à cette composante
                            // n'est pas encore dans mdl_local_ufrstudent, on l'y ajoute
                            if ($nbufrstudent == 0) {

                                $ufrrecord = new \stdClass();
                                $ufrrecord->userid = $user->id;
                                $ufrrecord->ufrcode = $ufrcodeyear;
                                $DB->insert_record('local_ufrstudent', $ufrrecord);
                            } else {

                                foreach ($listtempstudentufr as $tempstudentufr) {

                                    if ($tempstudentufr->ufrcode == $ufrcodeyear) {

                                        $tempstudentufr->stillexists = 1;
                                    }
                                }
                            }

                            //idem pour la VET

                            if ($DB->record_exists('course_categories',
                                    array('idnumber' => $codeetapeyear))) {

                                $vet = $DB->get_record('course_categories',
                                        array('idnumber' => $codeetapeyear));

                                $nbstudentvet = $DB->count_records('local_studentvet',
                                        array('studentid' => $user->id, 'categoryid' => $vet->id));

                                if ($nbstudentvet == 0) {

                                    $studentvetrecord = new \stdClass();
                                    $studentvetrecord->studentid = $user->id;
                                    $studentvetrecord->categoryid = $vet->id;

                                    $DB->insert_record('local_studentvet', $studentvetrecord);
                                } else {

                                    foreach ($listtempstudentvet as $tempstudentvet) {

                                        if ($tempstudentvet->categoryid == $vet->id) {

                                            $tempstudentvet->stillexists = 1;
                                        }
                                    }
                                }
                            }
                        }

                        // Supprimer les anciennes ufr/vet

                        if (isset($listtempstudentufr)) {

                            foreach ($listtempstudentufr as $tempstudentufr) {

                                if ($tempstudentufr->stillexists == 0) {

                                    echo "Désinscription de l'utilisateur $user->id de l'ufr"
                                            . " $tempstudentufr->ufrcode\n";

                                    $DB->delete_records('local_ufrstudent', array('userid' => $user->id,
                                        'ufrcode' => $tempstudentufr->ufrcode));

                                    echo "Utilisateur désinscrit\n";
                                }
                            }
                        }

                        if (isset($listtempstudentvet)) {

                            foreach ($listtempstudentvet as $tempstudentvet) {

                                if ($tempstudentvet->stillexists == 0) {

                                    echo "Désinscription de l'utilisateur $user->id de la vet"
                                            . " $tempstudentvet->categoryid\n";

                                    $DB->delete_records('local_studentvet', array('userid' => $user->id,
                                        'categoryid' => $tempstudentvet->categoryid));

                                    echo "Utilisateur désinscrit\n";
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}