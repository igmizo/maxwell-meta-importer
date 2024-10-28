(function( $ ) {
	let uploadInProgress = false;

  function showImportError(text) {
    const errorMessage = document.getElementById('import_error_message');

    if (errorMessage) {
      const errorMessageText = document.getElementById('import_error_message_text');

      if (errorMessageText) {
        errorMessageText.innerHTML = `Failed to start the import process. Error: ${text}`;
        errorMessage.classList.remove('hidden');
      }
    }
  }

  async function makeRequest(body, attempt = 1) {
    try {
      const response = await fetch(ajaxurl, {
        method: 'POST',
        body
      });

      const jsonResponse = await response.json();

      return jsonResponse
    } catch (error) {
      showImportError(error.message);
    }

    return [];
  }

	const calculateProgressPercentage = function (queue) {
		let percentage = queue.progress.toFixed(2);

		if (percentage.endsWith('.00')) {
			percentage = percentage.split('.').shift();
		}

		return percentage;
	};

	const createFileNameCell = function (queue) {
		const fileCell = document.createElement('td');
		const rowToggleButton = document.createElement('button');
		let fileName = '';

		if (queue.file) {
			fileName = queue.file.split('/').pop();
		}

		fileCell.innerText = fileName;
		fileCell.dataset.colname = 'File';

		fileCell.classList.add('column-primary');

		rowToggleButton.type = 'button';
		rowToggleButton.innerHTML = '<span class="screen-reader-text">Show more details</span>';

		rowToggleButton.classList.add('toggle-row');
		fileCell.appendChild(rowToggleButton);

		return fileCell;
	};

	const createDateCell = function (queue) {
		const dateCell = document.createElement('td');
		const queueStartDate = new Date(queue.created_at * 1000);

		dateCell.innerText = queueStartDate.toLocaleString();
		dateCell.dataset.colname = 'Start time';

		return dateCell;
	};

	const createCreatedCountCell = function (queue) {
		const createdCountCell = document.createElement('td');

		createdCountCell.innerText = queue.created_count;
		createdCountCell.dataset.colname = 'Created';

		return createdCountCell;
	};

	const createUpdatedCountCell = function (queue) {
		const updatedCountCell = document.createElement('td');

		updatedCountCell.innerText = queue.updated_count;
		updatedCountCell.dataset.colname = 'Updated';

		return updatedCountCell;
	};

	const createFailedCountCell = function (queue) {
		const failedCountCell = document.createElement('td');

		failedCountCell.innerText = queue.failed_count;
		failedCountCell.dataset.colname = 'Failed';

		return failedCountCell;
	};

	const createProgressCell = function (queue) {
		const progressCell = document.createElement('td');

		progressCell.innerText = calculateProgressPercentage(queue) + '%';
		progressCell.dataset.colname = 'Progress';

		return progressCell;
	};

	const createActionCell = function (queue) {
		const cell = document.createElement('td');
		const cancelButton = document.createElement('button');
		const restartButton = document.createElement('button');
		const progress = calculateProgressPercentage(queue);

		cell.classList.add('actions-cell');

		cancelButton.type = 'button';
		cancelButton.innerText = 'Cancel';
		cancelButton.dataset.key = queue.key;

		cancelButton.addEventListener('click', cancelFile);
		cancelButton.classList.add('button-link');
		cancelButton.classList.add('button-link-delete');

		restartButton.type = 'button';
		restartButton.innerText = 'Restart';
		restartButton.dataset.key = queue.key;

		restartButton.addEventListener('click', restartFile);
		restartButton.classList.add('button-link');

		if (progress !== '100') {
			cell.appendChild(cancelButton);
		}

		cell.appendChild(restartButton);

		return cell;
	};

	const createTableRow = function (queue) {
		const row = document.createElement('tr');

		row.appendChild(createFileNameCell(queue));
		row.appendChild(createDateCell(queue));
		row.appendChild(createCreatedCountCell(queue));
		row.appendChild(createUpdatedCountCell(queue));
		row.appendChild(createFailedCountCell(queue));
		row.appendChild(createProgressCell(queue));
		row.appendChild(createActionCell(queue));

		return row;
	};

	const createPostCell = function (error) {
		const cell = document.createElement('td');

		cell.innerText = error.post_id;
		cell.dataset.colname = 'PostId';

		return cell;
	};

	const createASINCell = function (error) {
		const cell = document.createElement('td');

		cell.innerHTML = Object.entries(error.asins)
			.filter(([key, value]) => value.length > 0)
			.map(([key, value]) => `<div>${key}: ${value}</div>`)
			.join('');

		cell.dataset.colname = 'ASIN';

		return cell;
	};

	const createErrorMessageCell = function (error) {
		const cell = document.createElement('td');

		cell.innerText = error.error;
		cell.dataset.colname = 'Error Message';

		return cell;
	};

	const createErrorTableRow = function (queue, error) {
		const row = document.createElement('tr');

		row.appendChild(createFileNameCell(queue));
		row.appendChild(createDateCell(queue));
		row.appendChild(createPostCell(error));
		row.appendChild(createASINCell(error));
		row.appendChild(createErrorMessageCell(error));

		return row;
	};

	const updateTables = function (data) {
		const tableBody = document.querySelector('#queue_table tbody');
		const errorTable = document.getElementById('error_table_wrapper');
		const errorTableBody = error_table.getElementsByTagName('tbody')[0];
		let queuesHaveErrors = false;

		if (tableBody && errorTableBody) {
			tableBody.innerHTML = '';
			errorTableBody.innerHTML = '';

			for (const queue of data) {
				tableBody.appendChild(createTableRow(queue));

				if (queue.amazon_errors && queue.amazon_errors.length > 0) {
					queuesHaveErrors = true;

					for (const error of queue.amazon_errors) {
						errorTableBody.appendChild(createErrorTableRow(queue, error));
					}
				}
			}

			if (queuesHaveErrors) {
				errorTable.classList.add('visible');
			} else {
				errorTable.classList.remove('visible');
			}
		}
	};

	const loadQueueHistory = function () {
		if (uploadInProgress) {
			return setTimeout(loadQueueHistory, 12 * 1000);
		}

		jQuery.ajax({
			type: 'GET',
			url: ajaxurl + '?action=get_queue_history',
			success: function (data) {
				const loaderWrapper = document.getElementById('queue_table_loader');
				const tableActionWrapper = document.getElementById('queue_table_actions');
				const errorElement = document.getElementById('queue_table_load_error');

				if (loaderWrapper) {
					loaderWrapper.classList.add('hidden');
				}

				if (tableActionWrapper) {
					tableActionWrapper.classList.remove('hidden');
				}

				if (errorElement) {
					errorElement.classList.add('hidden');
				}

				updateTables(data);
			},
			error: function (error) {
				console.error(error);

				const errorElement = document.getElementById('queue_table_load_error');

				if (errorElement) {
					errorElement.classList.remove('hidden');
				}
			},
			complete: function () {
				setTimeout(loadQueueHistory, 12 * 1000);
			},
			async: true
		});
	};

	const handle_submit = async function(file) {
		const formData = new FormData();
		const button = document.querySelector('#csv_upload_form button[type="submit"]');

		formData.append("action", "maxwell_handle_csv_upload");
		formData.append("csv_file", file, file.name);
		formData.append("upload_file", true);
		formData.append("post_type", jQuery('select[name="post_type"]').val());
		formData.append("taxonomy", jQuery('select[name="taxonomy"]').val());

		if (button) {
			button.setAttribute('disabled', 'disabled');
		}

		uploadInProgress = true;

    const response = await makeRequest(formData);

    if (button) {
      button.removeAttribute('disabled');
    }

    uploadInProgress = false;

    updateTables(response);
	}

	const updateProgress = function(data) {
		const $status = $(".status");
		const $progress = $("#progress");

		if(data.finished) {
			$status.html("Finished");
			$progress.val(data.total);
			return;
		}
		$progress.attr('max', data.total);
		$progress.val(data.current);
		$status.html("Importing " + data.current + " of " + data.total);
	}

	const clearCompletedQueues = function (event) {
		const buttonLoader = document.createElement('span');
		const buttonLoaderText = document.createElement('span');

		buttonLoader.classList.add('loader');
		buttonLoader.classList.add('small');

		buttonLoaderText.innerText = 'Cleaning...';

		event.target.innerHTML = '';

		event.target.classList.add('loading');
		event.target.appendChild(buttonLoader);
		event.target.appendChild(buttonLoaderText);

		jQuery.ajax({
			type: 'GET',
			url: ajaxurl + '?action=clean_queue_history',
			success: function (data) {
				event.target.innerHTML = 'Clear completed';

				event.target.classList.remove('loading');
				updateTables(data);
			},
			error: function (error) {
				console.error(error);
			},
			async: true
		});
	};

	const cancelFile = function (event) {
		const key = event.target.dataset.key;

		event.target.innerHTML = 'Loading...';

		event.target.classList.add('loading');

		jQuery.ajax({
			type: 'POST',
			url: ajaxurl + '?action=cancel_file',
			data: { schedule_key: key },
			success: updateTables,
			error: function (error) {
				console.error(error);

				event.target.innerHTML = 'Cancel';

				event.target.classList.remove('loading');
			},
			async: true
		});
	};

	const restartFile = function (event) {
		const key = event.target.dataset.key;

		event.target.innerHTML = 'Loading...';

		event.target.classList.add('loading');

		uploadInProgress = true;

		jQuery.ajax({
			type: 'POST',
			url: ajaxurl + '?action=restart_file',
			data: { schedule_key: key },
			success: updateTables,
			error: function (error) {
				console.error(error);

				event.target.innerHTML = 'Restart';

				event.target.classList.remove('loading');
			},
			complete: function () {
				uploadInProgress = false;
			},
			async: true
		});
	};

	const hideNotice = function () {
		document.getElementById('import_error_message').classList.add('hidden');
	};

	const xxxx = function (event) {
		var percent = 0;
		var position = event.loaded || event.position;
		var total = event.total;
		var progress_bar_id = "#progress-wrp";
		if (event.lengthComputable) {
			percent = Math.ceil(position / total * 100);
		}
		// update progressbars classes so it fits your code
		jQuery(progress_bar_id + " .progress-bar").css("width", +percent + "%");
		jQuery(progress_bar_id + " .status").text(percent + "%");
	};

	jQuery(document).ready(function ($) {
		$('#csv_upload_form').on('submit', function (e) {
			e.preventDefault();
			handle_submit($(this)[0].csv_file.files[0]);
			e.target.reset();
		});

		$('#queue_table_cleanup').on('click', clearCompletedQueues);
		$('#import_error_message .notice-dismiss').on('click', hideNotice);

		loadQueueHistory();
	});

})( jQuery );
