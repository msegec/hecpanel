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

App::uses('IdbrokerAppController', 'Idbroker.Controller');

class PeopleController extends IdbrokerAppController {

    public $name = 'People';
    public $components = array('Idbroker.Ldap');

    public function add() {
        if (!empty($this->request->data)) {
            $this->request->data['Person']['objectclass'] = array('top', 'organizationalperson', 'inetorgperson', 'person', 'posixaccount', 'shadowaccount');

            if ($this->request->data['Person']['password'] == $this->request->data['Person']['password_confirm']) {
                $this->request->data['Person']['userpassword'] = $this->request->data['Person']['password'];
                unset($this->request->data['Person']['password']);
                unset($this->request->data['Person']['password_confirm']);

                if (!isset($this->request->data['Person']['homedirectory']) && isset($this->request->data['Person']['uid'])) {
                    $this->request->data['Person']['homedirectory'] = '/home/' . $this->request->data['Person']['uid'];
                }

                $cn = $this->request->data['Person']['cn'];
                if ($this->Person->save($this->request->data)) {
                    $this->Session->setFlash($cn . ' was added Successfully.');
                    $id = $this->Person->id;
                    $this->redirect(array('action' => 'view', 'id' => $id));
                } else {
                    $this->Session->setFlash("$cn couldn't be created.");
                }
            } else {
                $this->Session->setFlash("Passwords don't match.");
            }
        }
        $attributes = array('uidnumber', 'uid', 'homedirectory');
        $preset = $this->autoSet($attributes);
        foreach ($this->request->data['Person'] as $key => $value) {
            $preset[$key] = $value;
        }
        $this->request->data['Person'] = $preset;

        $groups = $this->Ldap->getGroups(array('cn', 'gidnumber'), null, 'posixgroup');
        foreach ($groups as $group) {
            $groupList[$group['gidnumber']] = $group['cn'];
        }
        natcasesort($groupList);
        $this->set('groups', $groupList);
        $this->layout = 'people';
    }

    public function daySinceEpoch($offSet = 0) {
        return($offSet + floor(time() / 60 / 60 / 24));
    }

    public function view($id) {
        if (!empty($id)) {
            $filter = $this->Person->primaryKey . "=" . $id;
            $people = $this->Person->find('first', array('conditions' => $filter));
            $this->set(compact('people'));
        }
        $this->layout = 'people';
    }

    public function myAccount() {

        if (!empty($this->request->data)) {
            $udata = $this->LDAPAuth->user();
            $dn = $udata[$this->LDAPAuth->userModel]['dn'];
            $this->Person->id = $udata[$this->LDAPAuth->userModel][$this->Person->primaryKey];
            if (!empty($this->request->data['Person']['password']) && $this->request->data['Person']['password'] == $this->request->data['Person']['password_confirm']) {
                $this->request->data['Person']['userpassword'] = $this->request->data['Person']['password'];
                $this->request->data['Person']['shadowlastchange'] = $this->daySinceEpoch();

                $udata[$this->LDAPAuth->userModel]['bindPasswd'] = $this->request->data['Person']['password'];
            }
            unset($this->request->data['Person']['password']);
            unset($this->request->data['Person']['password_confirm']);
            if ($this->Person->save($this->request->data)) {
                $this->request->data = $this->Person->find('first', array('targetDn' => $dn, 'scope' => 'base'));
                $user = array_merge($udata[$this->LDAPAuth->userModel], $this->request->data['Person']);
                $this->Session->write($this->LDAPAuth->sessionKey, $user);
                $this->Session->setFlash('Your Account Has Been Updated.');
            } else {
                $this->Session->setFlash("Couldn't update your account, Sorry.  Please notify your support Team.");
            }
        } else {
            $udata = $this->LDAPAuth->user();
            $dn = $udata[$this->LDAPAuth->userModel]['dn'];
            $this->request->data['Person'] = $udata[$this->LDAPAuth->userModel];
        }
        $passwordReset = $this->Ldap->canChangePassword($dn);
        $groups = $this->Ldap->getGroups(array('cn'), $dn);
        $computers = $this->Ldap->getComputers(array('cn'), $dn);
        $sudoers = $this->Ldap->getSudoers(array('cn'), $dn);
        $this->set('passwordReset', $passwordReset);
        $this->set('groups', $groups);
        $this->set('computers', $computers);
        $this->set('sudoers', $sudoers);
        $this->layout = 'people';
    }

    public function delete($id = null) {
        $this->Person->id = $id;
        return $this->Person->del($id);
    }

}
