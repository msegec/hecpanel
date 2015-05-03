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
echo $this->Form->create('HostServer', array(
	'class' => 'well form-horizontal'
));
?>
<fieldset>
	<legend><?php echo __('Admin Add Host Server'); ?></legend>
	<?php
	echo $this->Form->input('servername');
	echo $this->Form->input('hostname');
	echo $this->Form->input('ip');
	echo $this->Form->input('instance_limit', array('label' => 'Instance Limit Override'));
	?>
</fieldset>
<div class="form-group">
	<?php echo $this->Form->submit(__('Submit'), Configure::read('Bootstrap.formButtonStyle')); ?>
</div>
<?php echo $this->Form->end(); ?>