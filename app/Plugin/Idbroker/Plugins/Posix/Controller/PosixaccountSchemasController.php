<?php
/**
 *  HE cPanel -- Hosting Engineers Control Panel
 *  Copyright (C) 2015  Dynamictivity LLC (http://www.hecpanel.com)
 *
 *   This program is free software: you can redistribute it and/or modify
 *   it under the terms of the GNU Affero General Public License as
 *   published by the Free Software Foundation, either version 3 of the
 *   License, or (at your option) any later version.
 *
 *   This program is distributed in the hope that it will be useful,
 *   but WITHOUT ANY WARRANTY; without even the implied warranty of
 *   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *   GNU Affero General Public License for more details.
 *
 *   You should have received a copy of the GNU Affero General Public License
 *   along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
?>

<?php

class PosixaccountSchemasController extends PosixAppController {

    var $name = 'PosixaccountSchemas';
    var $uses = array('Posix.PosixaccountSchema');
    var $components = array('RequestHandler', 'Ldap', 'SettingsHandler');
    var $helpers = array('Form', 'Html', 'Javascript', 'Ajax');

    function edit($id) {
        if (!empty($this->request->data)) {
            $this->PosixaccountSchema->id = $dn;
            $this->log("What I'm Going to save" . print_r($this->request->data['PosixaccountSchema'], true) . "\nFor the following ID:" . $this->PosixaccountSchema->id, 'debug');
            $result = $this->PosixaccountSchema->save($this->request->data);
            if ($result != false) {
                $this->Session->setFlash('Your Posix Account has been updated.');
            } else {
                $this->Session->setFlash('Failed to update');
            }
        } else {
            $options['scope'] = 'base';
            $options['targetDn'] = $id;
            $options['conditions'] = 'objectclass=posixaccount';
            $this->request->data = $this->PosixaccountSchema->find('first', $options);
        }
        $groups = $this->Ldap->getGroups(array('cn', 'gidnumber'), null, 'posixgroup');
        foreach ($groups as $group) {
            $groupList[$group['gidnumber']] = $group['cn'];
        }
        natcasesort($groupList);
        $this->set('groups', $groupList);
        $this->layout = 'ajax';
    }

    function findUniqueUidNumber() {
        $options['conditions'] = 'uidnumber=*';
        $options['scope'] = 'sub';
        $options['fields'] = array('uidnumber');
        $entrys = $this->PosixaccountSchema->find('all', $options);

        $list = array();
        foreach ($entrys as $entry) {
            array_push($list, $entry['PosixaccountSchema']['uidnumber']);
        }
        sort($list);
        $this->log("UIDS:" . print_r($list, true), 'debug');
        $found = false;
        $settings = $this->SettingsHandler->getSettings();
        if (isset($settings['PosixSetting']['uidnumbermin']) && !empty($settings['PosixSetting']['uidnumbermin'])) {
            $i = $settings['PosixSetting']['uidnumbermin'];
        } else {
            $i = $list[0];
        }
        while ($found === false) {
            if (in_array($i, $list)) {
                $i++;
            } else {
                $found = true;
            }
        }
        if (isset($settings['PosixSetting']['uidnumbermax']) && !empty($settings['PosixSetting']['uidnumbermax'])) {
            if ($i > $settings['PosixSetting']['uidnumbermax']) {
                $i = 'Out Of uidnumbers, consider increasing it in the Posix Settings.';
            }
        }
        $this->log("Found $i");
        $this->set('uidnumber', $i);
        return $i;
    }

}
