<?php namespace INTERSECT\RequestedAndRequiredFields;

use \REDCap as REDCap;
use \Project as Project;
use ExternalModules\AbstractExternalModule;

class RequestedAndRequiredFields extends \ExternalModules\AbstractExternalModule {

    function getTags($tags, $fields, $instruments) {
        // This is straight out of Andy Martin's example post on this:
        // https://community.projectredcap.org/questions/32001/custom-action-tags-or-module-parameters.html
        if (!class_exists('INTERSECT\RequestedAndRequiredFields\ActionTagHelper')) include_once('classes/ActionTagHelper.php');
        $action_tag_results = ActionTagHelper::getActionTags($tags, $fields, $instruments);
        return $action_tag_results;
    }

    function redcap_survey_page($project_id, $record, $instrument) {

		// Collect project settings
		$settings = $this->getProjectSettings();

		// Get all annotated fields
        $requestedTag = "@REQUESTED";
        $requiredTag = "@REQUIRED";
		$tags = array($requestedTag, $requiredTag);
        $annotatedFields = $this->getTags($tags, $fields=NULL, $instruments=$instrument);

        // Extract field names and build the new structure, with fieldType and description (or label if not set in the tag)
        $requestedFields = [];
        if (!empty($annotatedFields[$requestedTag]) && is_array($annotatedFields[$requestedTag])) {
            foreach (array_keys($annotatedFields[$requestedTag]) as $fieldName) {
                $fieldType = REDCap::getFieldType($fieldName);
                $description = trim($annotatedFields[$requestedTag][$fieldName][0], '"');
                if (strlen($description) == 0) $description = $this->getFieldLabel($fieldName);
                $requestedFields[$fieldName] = [
                    'type' => $fieldType,
                    'description' => REDCap::filterHtml($description)
                ];
            };
        };
        $requiredFields = [];
        if (!empty($annotatedFields[$requiredTag]) && is_array($annotatedFields[$requiredTag])) {
            foreach (array_keys($annotatedFields[$requiredTag]) as $fieldName) {
                $fieldType = REDCap::getFieldType($fieldName);
                $description = trim($annotatedFields[$requiredTag][$fieldName][0], '"');
				if (strlen($description) == 0) $description = $this->getFieldLabel($fieldName);
                $requiredFields[$fieldName] = [
                    'type' => $fieldType,
                    'description' => REDCap::filterHtml($description)
                ];
            };
        };

        // Add fields marked as required in the metadata to the requiredFields array
        if ($settings['designer-required'] == '1'){
            $Proj = new Project($project_id);
            // Loop through all fields in the current instrument
            $instrumentFields = REDCap::getFieldNames($instrument=$instrument);
            foreach ($instrumentFields as $this_field) {
                // If the field is required AND it is not already in thr requiredFields array
                if ($Proj->metadata[$this_field]['field_req'] == '1' && !array_key_exists($this_field, $requiredFields)){
                    $fieldType = REDCap::getFieldType($this_field); // Get the type
                    $description = $this->getFieldLabel($this_field); // Get the label
                    $requiredFields[$this_field] = [
                        'type' => $fieldType,
                        'description' => REDCap::filterHtml($description)
                    ];
                };
            };

            // Sort requiredFields by the order of the fields in the instrument, using the instrumentFields array from earlier
            // This is not necessary unless we are only using fields marked @REQUIRED as they are retrieved in instrument order
            $requiredFieldsSorted = [];
            foreach ($instrumentFields as $field) {
                if (isset($requiredFields[$field])) {
                    $requiredFieldsSorted[$field] = $requiredFields[$field];
                };
            };
            $requiredFields = $requiredFieldsSorted;
        };

        // Let's stop if both requestedFields and requiredFields are empty
        if (empty($requestedFields) && empty($requiredFields)){
            return;
        };

		// Language defaults
		$settings['modal-title'] = $settings['modal-title'] ?? $this->tt('modal-title-text');
		$settings['modal-requested-header'] = $settings['modal-requested-header'] ?? $this->tt('modal-requested-header-text');
		$settings['modal-required-header'] = $settings['modal-required-header'] ?? $this->tt('modal-required-header-text');
		$settings['modal-footer-norequired'] = $settings['modal-footer-norequired'] ?? $this->tt('modal-footer-norequired-text');
		$settings['modal-footer-required'] = $settings['modal-footer-required'] ?? $this->tt('modal-footer-required-text');
		$settings['requested-label'] = $settings['requested-label'] ?? $this->tt('requested-label-text');
		$settings['modal-cancel'] = $settings['modal-cancel'] ?? $this->tt('modal-cancel-text');
		$settings['modal-submit'] = $settings['modal-submit'] ?? $this->tt('modal-submit-text');

		// Colour defaults
		$settings['requested-hlcolour'] = $settings['requested-hlcolour'] ?? $this->tt('requested-hlcolour-hex');
		$settings['required-hlcolour'] = $settings['required-hlcolour'] ?? $this->tt('required-hlcolour-hex');
		$settings['requested-label-colour'] = $settings['requested-label-colour'] ?? $this->tt('requested-label-colour-hex');

        echo "<script>
            $('button[name=\"submit-btn-saverecord\"], button[name=\"submit-btn-saverepeat\"]').attr('onclick','$(this).button(\"disable\");checkReqdFields(this);return false;');
            var requestedFields = " . json_encode($requestedFields) . ";
            var requiredFields = " . json_encode($requiredFields) . ";
            var highlightEmptyOnly = " . ($settings['highlight-empty-only'] == '1' ? 'true' : 'false') . ";
            $.each(requestedFields, function(fieldName, fieldInfo) {
                var fieldRow = $('tr#'+fieldName+'-tr');
                var fieldType = fieldInfo.type;
                var desc = fieldInfo.description;
                if (!fieldRow.length) {
                    delete requestedFields[fieldName];
                }
                var fieldClass = fieldRow.attr('class');
                if (fieldClass && fieldClass.indexOf('@HIDDEN') !== -1) {
                    delete requestedFields[fieldName];
                }
            });
            $.each(requiredFields, function(fieldName, fieldInfo) {
                var fieldRow = $('tr#'+fieldName+'-tr');
                var fieldType = fieldInfo.type;
                var desc = fieldInfo.description;
                if (!fieldRow.length) {
                    delete requiredFields[fieldName];
                }
                var fieldClass = fieldRow.attr('class');
                if (fieldClass && fieldClass.indexOf('@HIDDEN') !== -1) {
                    delete requiredFields[fieldName];
                }
            });
            function fieldIsEmpty(fieldName, fieldType){
                var fieldIsEmpty = false;
                var fieldRow = $('tr#'+fieldName+'-tr');
                if (!fieldRow.is(':visible')) {
                    return fieldIsEmpty;
                }
                switch (fieldType) {
                    case 'text':
                    case 'radio':
                    case 'truefalse':
                    case 'yesno':
                    case 'slider':
                    case 'file':
                    case 'signature':
                        fieldIsEmpty = ($('input[name=\"'+fieldName+'\"]').val()==='');
                        break;
                    case 'notes':
                        fieldIsEmpty = ($('textarea[name=\"'+fieldName+'\"]').val()==='');
                        break;
                    case 'dropdown':
                    case 'sql':
                        fieldIsEmpty = ($('select[name=\"'+fieldName+'\"]').val()==='');
						break;
					case 'checkbox':
						fieldIsEmpty = (!$('tr#' + fieldName+ '-tr').find('input[type=\"checkbox\"]').is(':checked'));
						break;
					case 'calc':
						fieldIsEmpty = false;
						break;
					case 'descriptive':
						fieldIsEmpty = false;
						break;
					case 'advcheckbox':
						fieldIsEmpty = false;
						break;
                }
                return fieldIsEmpty;
            }
            function createEmptyReport(fieldArray){
                var empties = [];
                $.each(fieldArray, function(fieldName, fieldInfo) {
                    var fieldType = fieldInfo.type;
                    var fieldDesc = fieldInfo.description;
                    if (fieldIsEmpty(fieldName, fieldType)) {
                        empties.push(fieldDesc);
                    }
                });
                return empties;
            };
			function addClassToField(fieldName, className){
                var fieldRow = $('tr#'+fieldName+'-tr');
				fieldRow.addClass(className);
			};
            function applyHighlightClasses(fieldArray, className){
                $.each(fieldArray, function(fieldName, fieldInfo) {
                    if (!highlightEmptyOnly || fieldIsEmpty(fieldName, fieldInfo.type)) {
                        addClassToField(fieldName, className);
                    }
                });
            };
            function checkReqdFields(clickedBtn){
				$('#requested-list').empty();
				$('#required-list').empty();
                var emptyRequested = createEmptyReport(requestedFields);
                var emptyRequired = createEmptyReport(requiredFields);
                if (emptyRequested.length + emptyRequired.length == 0){
                    dataEntrySubmit(clickedBtn);
                } else {
					if (emptyRequested.length > 0) {
						$('#modal-requested-header').show();
						// Re-enable the modal's submit button
						$('button#confirmSubmit').prop('disabled', false);
						// Create a <ul> element
						var requestedUl = document.createElement('ul');
						// Loop through the descriptions and create <li> elements
						emptyRequested.forEach(function(description) {
							var li = document.createElement('li');
							li.innerHTML = description;
							requestedUl.appendChild(li);
						});
						document.getElementById('requested-list').appendChild(requestedUl);
					} else {
						$('#modal-requested-header').hide();
					}
					if (emptyRequired.length > 0) {
						$('#modal-required-header').show();
						// Disable modal's submit button
						$('button#confirmSubmit').prop('disabled', true);
						$('#modal-action').text('". $settings['modal-footer-required']  ."');
						// Create a <ul> element
						var requiredUl = document.createElement('ul');
						// Loop through the descriptions and create <li> elements
						emptyRequired.forEach(function(description) {
							var li = document.createElement('li');
							li.innerHTML = description;
							requiredUl.appendChild(li);
						});
						document.getElementById('required-list').appendChild(requiredUl);
					} else {
						$('#modal-required-header').hide();
					}

					// Show modal
					$('#confirmationModal').modal('show');
					// Handle the OK button click in the modal
					$('button#confirmSubmit').on('click', function() {
						// Hide the modal
						$('#confirmationModal').modal('hide');
						dataEntrySubmit(clickedBtn);
					});
					// Handle the Cancel button click in the modal
					$('#cancelSubmit, .close').on('click', function() {
						// Re-enable the submit button
						$('#confirmationModal').modal('hide');
						$('button[name=\"submit-btn-saverecord\"], button[name=\"submit-btn-saverepeat\"]').button('enable')
						applyHighlightClasses(requestedFields, 'em-requested');
						applyHighlightClasses(requiredFields, 'em-required');
					});
					// Re-enable the submit button if the modal is closed without confirmation
					$('#confirmationModal').on('hidden.bs.modal', function () {
						$('button[name=\"submit-btn-saverecord\"], button[name=\"submit-btn-saverepeat\"]').button('enable')
						applyHighlightClasses(requestedFields, 'em-requested');
						applyHighlightClasses(requiredFields, 'em-required');
					});
                }
            };
		</script>";

		if ($settings['highlight']) {
			echo "<style>
				tr.em-requested .labelrc, tr.em-requested .data {
					background: " . $settings['requested-hlcolour'] . ";
				}
				tr.em-required .labelrc, tr.em-required .data {
					background: " . $settings['required-hlcolour'] . ";
				}
			</style>";
		}

		echo "<div class='modal fade' id='confirmationModal' tabindex='-1' role='dialog' aria-labelledby='confirmationModalLabel' aria-hidden='true'>
				<div class='modal-dialog' role='document'>
					<div class='modal-content'>
						<div class='modal-header'>
							<h5 class='modal-title' id='confirmationModalLabel'>".$settings['modal-title']."</h5>
							<button type='button' class='close' data-dismiss='modal' aria-label='Close'>
								<span aria-hidden='true'>&times;</span>
							</button>
						</div>
						<div class='modal-body'>
							<p id='modal-requested-header'>".$settings['modal-requested-header']."</p>
							<div " . ($settings['highlight'] ? "style='background: " . $settings['requested-hlcolour'] . ";'" : "") . " id='requested-list'></div>
							<p id='modal-required-header'>".$settings['modal-required-header']."</p>
							<div " . ($settings['highlight'] ? "style='background: " . $settings['required-hlcolour'] . ";'" : "") . " id='required-list'></div>
							<p id='modal-action'>".$settings['modal-footer-norequired']."</p>
						</div>
						<div class='modal-footer'>
							<button type='button' class='btn btn-secondary' data-dismiss='modal' id='cancelSubmit'>".$settings['modal-cancel']."</button>
							<button type='button' class='btn btn-primary' id='confirmSubmit'>".$settings['modal-submit']."</button>
						</div>
					</div>
				</div>
			</div>";
		if ($settings['show-requested']) {
			echo "<script>
				var requestedLabelDiv = '<div class=\"requestedlabel\" aria-label=\"response requested\">" . $settings['requested-label'] . "</div>';
				document.addEventListener('DOMContentLoaded', function() {
					$.each(requestedFields, function(fieldName) {
						var fieldRow = $('tr#'+fieldName+'-tr');
						fieldRow.find('label#label-' + fieldName + ' div[data-mlm-field=\"' + fieldName + '\"]').append(requestedLabelDiv);
					});
				});
				</script>
				<style>
					.requestedlabel {
						font-size: 12px;
						color: " . $settings['requested-label-colour'] . ";
						font-weight: normal;
					}
				</style>";
		}
		if ($settings['disable-greenhl']) {
		echo "<script>
				doGreenHighlight = function() {};
			</script>";
		}
    }
}
