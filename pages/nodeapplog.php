<!-- Begin toolbar -->
<div class="toolbar">
	<div class="toolbar-inner">
		<div class="toolbar-buttons">
			<a class="button button-secondary button-back" href="?p=nodeapp">
				<i class="fas fa-arrow-left icon-blue"></i>Back
			</a>
			<a class="button button-secondary" href="?p=nodeapplog&pm2_id=<?= $_GET['pm2_id']; ?>">
				<i class="fas fa-rotate-right icon-green"></i>Refresh
			</a>
		</div>
	</div>
</div>
<!-- End toolbar -->
<div class="container">
	<textarea class="form-control" style="width: 100%; height: 50vh; overflow-y: scroll;" readonly><?php 
			$pm2_id = $_GET['pm2_id'];
			$pm2_id = filter_var($pm2_id, FILTER_SANITIZE_NUMBER_INT);
			$pm2_log = $hcpp->nodeapp->get_pm2_log($pm2_id);
			echo $pm2_log;
	?>
	</textarea>
</div>
<script>
	// Scroll to bottom of textarea
    document.addEventListener('DOMContentLoaded', function() {
        var textarea = document.getElementById('logTextarea');
        textarea.scrollTop = textarea.scrollHeight;
    });
</script>
