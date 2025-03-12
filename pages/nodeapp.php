<?php
	$hcpp->log( $_REQUEST );

	// Handle actions
	if ( isset( $_REQUEST['action'] ) ) {
		$action = $_REQUEST['action'];
        if ( isset($_REQUEST['pm2_id']) ) {
            $pm2_ids = [$_REQUEST['pm2_id']];
        } elseif ( isset($_REQUEST['pm2_ids']) ) {
            $pm2_ids = $_REQUEST['pm2_ids'];
        } else {
            $pm2_ids = [];
        }
		if ( $action == 'start' || $action == 'restart' ) {
			$hcpp->nodeapp->restart_pm2_ids( $pm2_ids );
		}elseif ( $action == 'stop' ) {
			$hcpp->nodeapp->stop_pm2_ids( $pm2_ids );
		}
	}
?>
<!-- Begin toolbar -->
<div class="toolbar">
	<div class="toolbar-inner">
		<div class="toolbar-buttons">
			<a class="button button-secondary button-back" href="/list/web/">
				<i class="fas fa-arrow-left icon-blue"></i>Back
			</a>
			<a class="button button-secondary" href="?p=nodeapp">
				<i class="fas fa-rotate-right icon-green"></i>Refresh
			</a>
		</div>
		<div class="toolbar-right">
			<form x-data="" x-bind="BulkEdit" action="?p=nodeapp&action=bulk" method="post">
				<select class="form-select" name="action">
					<option value="">Apply to selected</option>
					<option value="stop">Stop</option>
					<option value="restart">Restart</option>
				</select>
				<button type="submit" class="toolbar-input-submit" title="Apply to selected">
					<i class="fas fa-arrow-right"></i>
				</button>
			</form>
		</div>
	</div>
</div>
<!-- End toolbar -->
<div class="container">
	<div class="units-table js-units-container">
		<div class="units-table-header">
			<div class="units-table-cell">
				<input type="checkbox" class="js-toggle-all-checkbox" title="Select all">
			</div>
			<div class="units-table-cell">Name</div>
			<div class="units-table-cell"></div>
			<div class="units-table-cell">NodeJS</div>
			<div class="units-table-cell u-text-center">Restarts</div>
			<div class="units-table-cell u-text-center">Uptime</div>
			<div class="units-table-cell u-text-center">CPU</div>
			<div class="units-table-cell u-text-center">Memory</div>
		</div>

		<?php
			global $hcpp;
			$list = $hcpp->nodeapp->get_pm2_list();
			$i = 0;
			$removed_apps = '';
			$removed_ids = [];
			foreach( $list as $app ){
				$hcpp->log( $app );
				$pm_exec_path = $app['pm2_env']['pm_exec_path'];
				$name = $app['name'];
				$pm2_id = $app['pm_id'];
				if ( !file_exists( $pm_exec_path) ) {
					$removed_apps .= "<p class=\"u-mb10\"><b>$name</b> was removed; missing $pm_exec_path.</p>";
					$removed_ids[] = $pm2_id;
					$hcpp->log("$name was removed; missing $pm_exec_path.");
					continue;
				}
				$i++;
				$restarts = $app['pm2_env']['restart_time'];
				$version = 'v' . $app['pm2_env']['node_version'];

				// Calculate uptime
				$pm_uptime = $app['pm2_env']['pm_uptime'];
				$uptime_seconds = (time() * 1000 - $pm_uptime) / 1000;
				$days = floor($uptime_seconds / (24 * 3600));
				$uptime_seconds %= (24 * 3600);
				$hours = floor($uptime_seconds / 3600);
				$uptime_seconds %= 3600;
				$minutes = floor($uptime_seconds / 60);
				if ( $days > 0 ) {
					$uptime = $days . ' days';
				} else if ( $hours > 0 ) {
					$uptime = $hours . ' hours';
				} else {
					$uptime = $minutes . ' minutes';
				}
				$status = $app['pm2_env']['status'];
				if ( $status == 'online' ) {
					$status_class = 'fas fa-circle-check icon-green u-mr5';
				} else {
					$status_class = 'fas fa-circle-minus icon-red u-mr5';
					$uptime = $status;
				}

				// Calculate memory and cpu
				$memory = round($app['monit']['memory'] / (1024 * 1024), 1) . ' MB';
				$cpu = round($app['monit']['cpu'], 1);
				?>
				<!-- Begin nodeapp loop -->
				<div class="units-table-row  js-unit">
					<div class="units-table-cell">
						<div>
							<input id="check<?= $i; ?>" class="js-unit-checkbox" type="checkbox" title="Select" name="pm2_ids[]" value="<?= $pm2_id; ?>">
							<label for="check<?= $i; ?>" class="u-hide-desktop">Select</label>
						</div>
					</div>
					<div class="units-table-cell units-table-heading-cell u-text-bold">
						<span class="u-hide-desktop">Name:</span>
						<i class="<?= $status_class; ?>"></i>
						<a href="?p=nodeapplog&pm2_id=<?= $pm2_id; ?>" title="Log">
							<?= $name; ?>				
						</a>
					</div>
					<div class="units-table-cell">
						<ul class="units-table-row-actions">
							<li class="units-table-row-action shortcut-enter" data-key-action="href">
								<a class="units-table-row-action-link" href="?p=nodeapplog&pm2_id=<?= $pm2_id; ?>" title="Log">
									<i class="fas fa-list icon-blue"></i>
									<span class="u-hide-desktop">Log</span>
								</a>
							</li>
							<?php if ( $status == 'online' ) { ?>
								<li class="units-table-row-action shortcut-s" data-key-action="js">
									<a class="units-table-row-action-link data-controls js-confirm-action" href="?p=nodeapp&action=restart&pm2_id=<?= $pm2_id; ?>" title="Restart" data-confirm-title="Restart" data-confirm-message="Are you sure you want to restart the <?= $name; ?> NodeApp?">
										<i class="fas fa-arrow-rotate-left icon-highlight"></i>
										<span class="u-hide-desktop">Restart</span>
									</a>
								</li>
								<li class="units-table-row-action shortcut-delete" data-key-action="js">
									<a class="units-table-row-action-link data-controls js-confirm-action" href="?p=nodeapp&action=stop&pm2_id=<?= $pm2_id; ?>" title="Stop" data-confirm-title="Stop" data-confirm-message="Are you sure you want to stop the <?= $name; ?> NodeApp?">
										<i class="fas fa-stop icon-red"></i>
										<span class="u-hide-desktop">Stop</span>
									</a>
								</li>
							<?php } else { ?>
								<li class="units-table-row-action shortcut-delete" data-key-action="js">
									<a class="units-table-row-action-link data-controls js-confirm-action" href="?p=nodeapp&action=start&pm2_id=<?= $pm2_id; ?>" title="Start" data-confirm-title="Start" data-confirm-message="Are you sure you want to start the <?= $name; ?> NodeApp?">
										<i class="fas fa-play icon-green"></i>
										<span class="u-hide-desktop">Start</span>
									</a>
								</li>
							<?php } ?>
						</ul>
					</div>
					<div class="units-table-cell">
						<span class="u-hide-desktop u-text-bold">NodeJS Runtime:</span>
						<?= $version; ?>			
					</div>
					<div class="units-table-cell u-text-bold u-text-center-desktop">
						<span class="u-hide-desktop">Restarts:</span>
						<?= $restarts; ?>				
					</div>
					<div class="units-table-cell u-text-bold u-text-center-desktop">
						<span class="u-hide-desktop">Uptime:</span>
						<?= $uptime; ?>			
					</div>
					<div class="units-table-cell u-text-bold u-text-center-desktop">
						<span class="u-hide-desktop">CPU:</span>
						<?= $cpu; ?>%				
					</div>
					<div class="units-table-cell u-text-bold u-text-center-desktop">
						<span class="u-hide-desktop">Memory:</span>
						<?= $memory; ?>				
					</div>
				</div>
				<?php
			}
		?>
	</div>
	<?php
		if ( $removed_apps != '' ) {
			$hcpp->nodeapp->delete_pm2_ids( $removed_ids );
			?>
			<br>
			<div class="alert alert-info u-mb10" role="alert" style="max-width: 640px; margin: 0 auto;">
				<i class="fas fa-info"></i>
				<div>
					<p class="u-mb10">Removed Missing NodeApp</p>
					<?= $removed_apps; ?>
				</div>
			</div>
			<?php
		}
	?>
	<div class="units-table-footer">
		<p>
			<?= $i; ?> <?= $i == 1 ? 'NodeApp' : 'NodeApps'; ?>
		</p>
	</div>
</div>
