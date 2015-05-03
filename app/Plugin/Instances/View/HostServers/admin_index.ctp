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

<div class="table-responsive">
    <h2><?php echo __('Host Servers'); ?></h2>
	<table class="table table-striped">
        <thead>
            <tr>
				<th><?php echo $this->Paginator->sort('servername'); ?></th>
				<th><?php echo $this->Paginator->sort('hostname'); ?></th>
				<th><?php echo $this->Paginator->sort('ip'); ?></th>
				<th><?php echo $this->Paginator->sort('instance_count'); ?></th>
				<th><?php echo $this->Paginator->sort('instance_limit', 'Instance Limit Override'); ?></th>
				<th><?php echo $this->Paginator->sort('updated'); ?></th>
				<th class="actions"><?php echo __('Actions'); ?></th>
            </tr>
        </thead>
        <tbody>
			<?php foreach ($hostServers as $hostServer): ?>
				<tr>
					<td><?php echo h($hostServer['HostServer']['servername']); ?>&nbsp;</td>
					<td><?php echo h($hostServer['HostServer']['hostname']); ?>&nbsp;</td>
					<td><?php echo h($hostServer['HostServer']['ip']); ?>&nbsp;</td>
					<td><?php echo h($hostServer['HostServer']['instance_count']); ?>&nbsp;</td>
					<td><?php echo h($hostServer['HostServer']['instance_limit']); ?>&nbsp;</td>
					<td><?php echo h($hostServer['HostServer']['updated']); ?>&nbsp;</td>
					<td class="actions">
						<?php echo $this->Html->buttonGroup(
							array(
								$this->Html->button(array('cog', 'default', 'xs'), array('action' => 'edit', $hostServer['HostServer']['id']), array('escape' => false, 'title' => __('Edit'))),
								$this->Html->postButton(array('trash', 'default', 'xs'), array('action' => 'delete', $hostServer['HostServer']['id']), array('escape' => false, 'title' => __('Delete')), __('Are you sure you want to delete # %s?', $hostServer['HostServer']['id']))
							)	
						); ?>
					</td>
				</tr>
			<?php endforeach; ?>
        </tbody>
    </table>
	<?php echo $this->element('paging'); ?>
</div>
<?php echo $this->element('add_button'); ?>