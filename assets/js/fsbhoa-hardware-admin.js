
/**
 * Handles JavaScript functionality for the Hardware Management pages,
 * specifically initializing the DataTables for the controller and gate lists.
 */
jQuery(document).ready(function($) {

    // Initialize the DataTables library on the controller list table.
    var controllerTable = $('#fsbhoa-controller-table');
    if (controllerTable.length && controllerTable.find('tbody tr td').length > 1) {
        controllerTable.DataTable({
            'paging': false, // As originally designed, no pagination
            'searching': false, // No search box for this simple list
            'info': false,
            'autoWidth': true,
            'order': [
                [1, 'asc']
            ], // Default sort by the 2nd column (Friendly Name)
            'columnDefs': [{
                'orderable': false,
                'targets': 'no-sort'
            }]
        });
    }



    // Handler for the new Factory Reset button
    // Note: We use a delegated event handler attached to the body in case the form is loaded via AJAX.
    $('body').on('click', '#fsbhoa-factory-reset-button', function() {
        if (!confirm('WARNING: This will completely wipe all cards and settings from this controller. This cannot be undone. Are you absolutely sure you want to proceed?')) {
            return;
        }

        const button = $(this);
        const controllerId = button.data('controller-id');
        const originalText = button.text();

        button.text('Resetting...').prop('disabled', true);

        $.ajax({
            url: fsbhoa_hardware_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'fsbhoa_factory_reset',
                nonce: fsbhoa_hardware_vars.reset_nonce,
                controller_id: controllerId
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data);
                    window.location.reload(); // Reload the page to show the sync banner
                } else {
                    alert('Error: ' + response.data);
                    button.text(originalText).prop('disabled', false);
                }
            },
            error: function() {
                alert('An unknown error occurred while communicating with the server.');
                button.text(originalText).prop('disabled', false);
            }
        });
    });


// Handler for the Force Full Rebuild button
    $('body').on('click', '#fsbhoa-trigger-rebuild-button', function() {
        if (!confirm('This will perform a FULL rebuild, wiping all cards from all controllers and reprogramming them from the database. This may take several minutes. Are you sure?')) {
            return;
        }

        const button = $(this);
        const originalText = button.text();
        button.text('Scheduling...').prop('disabled', true);

        $.ajax({
            url: fsbhoa_hardware_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'fsbhoa_trigger_rebuild',
                nonce: fsbhoa_hardware_vars.rebuild_nonce,
            },
            success: function(response) {
                if (response.success) {
                    // Reload the page. The sync banner will automatically appear and show progress.
                    window.location.reload();
                } else {
                    alert('Error: ' + response.data);
                    button.text(originalText).prop('disabled', false);
                }
            },
            error: function() {
                alert('An unknown error occurred while communicating with the server.');
                button.text(originalText).prop('disabled', false);
            }
        });
    });
});
