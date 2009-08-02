/**
 * Xibo - Digitial Signage - http://www.xibo.org.uk
 * Copyright (C) 2006,2007,2008,2009 Daniel Garner
 *
 * This file is part of Xibo.
 *
 * Xibo is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version. 
 *
 * Xibo is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Xibo.  If not, see <http://www.gnu.org/licenses/>.
 */

$(document).ready(function(){
	
	// Setup the dialogs
    $('#div_dialog').dialog({
        title: "Xibo",
        width: "500px",
        height: "240px",
        draggable: true,
        resizable: false,
        bgiframe: true,
		autoOpen: false
    }).parent().parent().css("z-index", "300");
	
	// Setup the dialogs
    $('#help_dialog').dialog({
        title: "Xibo Help",
        width: "500px",
        height: "240px",
        draggable: true,
        resizable: false,
        bgiframe: true,
		autoOpen: false
    }).parent().parent().css("z-index", "300");
	
    $('#system_message').dialog({
        title: "Application Message",
        width: "320px",
        height: "120px",
        draggable: false,
        resizable: false,
        bgiframe: true,
		autoOpen: false,
		modal: true
    }).parent().parent().css("z-index", "300");
	
    $('#system_working').dialog({
        title: "Progress...",
        width: "240px",
        height: "90px",
        draggable: false,
        resizable: false,
        bgiframe: true,
		autoOpen: false
    }).ajaxStart(function(){
    	$(this).dialog("open");
    }).ajaxComplete(function(){
        $(this).dialog("close");
    }).parent().parent().css("z-index", "300");

	
	XiboInitialise();
});

/**
 * Initialises the page/form
 * @param {Object} Optional: scope (the form or page)
 */
function XiboInitialise(scope){
	
	// If the scope isnt defined then assume the entire page
	if (scope == undefined || scope == "") {
		scope = " ";
	}

	// Search for any grids on the page and render them 
	$(scope + " .XiboGrid").each(function(){
		
		var gridId = $(this).attr("id");
		
		// For each one setup the filter form bindings
		$('.XiboFilter form :input', this).change(function(){
			XiboGridRender(gridId);
		});
		
		$('.XiboFilter form', this).submit(function(){
			// We dont actually want the form to be submittable (just in case)
			return false;
		});
		
		// Render
		XiboGridRender(gridId);
	});
	
	// Search for any Buttons / Links on the page that are used to load forms
	$(scope + " .XiboFormButton").click(function(){
		
		var formUrl = $(this).attr("href");
		
		XiboFormRender(formUrl);
		
		return false;
	});
	
	// Search for any forms that will need submitting
    $(scope + ' .XiboForm').submit(function(){
        XiboFormSubmit(this);
        
        return false;
    });
    
    // Search for any text forms that will need submitting
    $(scope + ' .XiboTextForm').submit(function(){
        var inst = FCKeditorAPI.GetInstance("ta_text");
        
        $('#ta_text').val(inst.GetHTML());
        
        XiboFormSubmit(this);
        
        return false;
    });
	
	// Search for any help enabled elements
	$(scope + " .XiboHelpButton").click(function(){
		
		var formUrl = $(this).attr("href");
		
		XiboHelpRender(formUrl);
		
		return false;
	});
}

/**
 * Renders any Xibo Grids that are detected
 * @param {Object} gridId
 */
function XiboGridRender(gridId){

    // Grid ID tells us which grid we need to render
	var gridDiv 	= '#' + gridId;
    var filter 		= $('#' + gridId + ' .XiboFilter form');
    var outputDiv 	= $('#' + gridId + ' .XiboData ');
    
    // AJAX call to get the XiboData	
    $.ajax({
        type: "post",
        url: "index.php?ajax=true",
        dataType: "json",
        data: filter.formSerialize(),
        success: function(response){
        
            var respHtml;
            
            if (response.success) {
                respHtml = response.html;
            }
            else {
                // Login Form needed?
                if (response.login) {
                    LoginBox(response.message);
                    return false;
                }
                else {
                    // Just an error we dont know about
                    respHtml = response.message;
                }
            }
            
            $(outputDiv).html(respHtml);
            
            // Do we need to do anything else now?
            if (response.sortable) {
                // Call paging
                var sortingDiv = response.sortingDiv;
                
                if ($('tbody', sortingDiv).html() != "") {
                    $(sortingDiv).tablesorter({
                        sortList: [[1, 0]],
                        widthFixed: true
                    })
                };
			}
			
			// Call XiboInitialise for this form
			XiboInitialise(gridDiv);
            
            return false;
        }
    });
    
    //so that we dont submit forms
    return false;
}

/**
 * Renders the formid provided
 * @param {String} formId
 */
function XiboFormRender(formUrl) {
	
	// Prepare the Dialog
	$('#div_dialog').dialog("close");
	$('#div_dialog').html("");
	
	// Call with AJAX
    $.ajax({
        type: "get",
        url: formUrl + "&ajax=true",
        cache: false,
        dataType: "json",
        success: function(response){
        
			// Was the Call successful
            if (response.success) {
				// Set the dialog HTML to be the response HTML
                $('#div_dialog').html(response.html);
				
				// Is there a title for the dialog?
				if (response.dialogTitle != undefined && response.dialogTitle != "") {
					// Set the dialog title
					$('#div_dialog').parent().children().each(function(){
						$(".ui-dialog-title", this).html(response.dialogTitle);
					});
				}
				
				// Do we need to alter the dialog size?
				if (response.dialogSize) {
					$('#div_dialog').parent().parent().width(response.dialogWidth).height(response.dialogHeight);
				}
				
                // Do we have to call any functions due to this success?
                if (response.callBack != "" && response.callBack != undefined) {
                    eval(response.callBack)(name);
                }

				$('#div_dialog').dialog("open");
                             
                // Focus in the first form element
                $('input[@type=text]', '#div_dialog').eq(0).focus();
				
				// Call Xibo Init for this form
				XiboInitialise("#div_dialog");
            }
			else {
				// Login Form needed?
	            if (response.login) {
	                LoginBox(response.message);
	                return false;
	            }
	            else {
	                // Just an error we dont know about
					if (response.message == undefined) {
						SystemMessage(response);
					}
					else {
		                SystemMessage(response.message);
					}
	            }
			}
            
            return false;
        }
    });
	
	// Dont then submit the link/button	
	return false;
}

/**
 * Submits the Form
 * @param {Object} form
 */
function XiboFormSubmit(form) {
	// Get the URL from the action part of the form)
	var url = $(form).attr("action") + "&ajax=true";
	
	$.ajax({type:"post", url:url, cache:false, dataType:"json", data:$(form).serialize(), success: XiboSubmitResponse});
	
	return;
}

/**
 * Handles the submit response from an AJAX call
 * @param {Object} response
 */
function XiboSubmitResponse(response) {
	// Did we actually succeed
	if (response.success) {
		// Success - what do we do now?
		
		// We might need to keep the form open
		if (!response.keepOpen) {
			$('#div_dialog').dialog("close");
		}
		
		// Should we display the message?
		if (!response.hideMessage || response.message != '') {
			SystemMessage(response.message);
		}
		
		// Do we need to fire a callback function?
		if (response.callBack != null && response.callBack != "") {
            eval(response.callBack)(name);
        }
		
		// Do we need to load a new form?
		if (response.loadForm) {
			// We need: uri, callback, onsubmit
			var uri = response.loadFormUri;
			
			// File forms give the URI back with &amp's in it
			uri = unescape(uri);
		
			XiboFormRender(uri);
		}
		
		// Should we refresh the window or refresh the Grids?
		if (response.refresh) {
			// We need to refresh - check to see if there is a new location provided
			if (response.refreshLocation == undefined || response.refreshLocation == "") {
				// If not refresh the current location
		    	window.location.reload();
			}
			else {
				// Refresh to the new location
				window.location = response.refreshLocation;
			}
		}
		else {
			// We should refresh the grids (this is a global refresh)
			$(" .XiboGrid").each(function(){

				var gridId = $(this).attr("id");
				
				// Render
				XiboGridRender(gridId);
			});
		}
	}
	else {
		// Why did we fail? 
		if (response.login) {
			// We were logged out
            LoginBox(response.message);
            return false;
        }
        else {
            // Likely just an error that we want to report on
            SystemMessage(response.message);
        }
	}
	
	return false;
}

/**
 * Renders the formid provided
 * @param {String} formId
 */
function XiboHelpRender(formUrl) {
	
	// Prepare the Dialog
	$('#help_dialog').dialog("close");
	$('#help_dialog').html("");
	
	// Call with AJAX
    $.ajax({
        type: "get",
        url: formUrl + "&ajax=true",
        cache: false,
        dataType: "json",
        success: function(response){
        
			// Was the Call successful
            if (response.success) {
				// Set the dialog HTML to be the response HTML
                $('#help_dialog').html(response.html);
				
				// Is there a title for the dialog?
				if (response.dialogTitle != undefined && response.dialogTitle != "") {
					// Set the dialog title
					$('#help_dialog').parent().children().each(function(){
						$(".ui-dialog-title", this).html(response.dialogTitle);
					});
				}
				
				// Do we need to alter the dialog size?
				if (response.dialogSize) {
					$('#help_dialog').parent().parent().width(response.dialogWidth).height(response.dialogHeight);
				}
				
                // Do we have to call any functions due to this success?
                if (response.callBack != "" && response.callBack != undefined) {
                    eval(response.callBack)(name);
                }

				$('#help_dialog').dialog("open");
                             
                // Focus in the first form element
                $('input[@type=text]', '#help_dialog').eq(0).focus();
				
				// Call Xibo Init for this form
				XiboInitialise("#help_dialog");
            }
			else {
				// Login Form needed?
	            if (response.login) {
	                LoginBox(response.message);
	                return false;
	            }
	            else {
	                // Just an error we dont know about
					if (response.message == undefined) {
						SystemMessage(response);
					}
					else {
		                SystemMessage(response.message);
					}
	            }
			}
            
            return false;
        }
    });
	
	// Dont then submit the link/button	
	return false;
}

/**
 * Display a login box
 * @param {String} message
 */
function LoginBox(message) {
    $('#div_dialog').html(message);
    
    //capture the form submit
    $('.XiboForm').submit(function() {
        XiboFormSubmit(this);
        return false;
    });
    
    //do any changes to the dialog form here
    $('#div_dialog').parent().children().each(function(){
        $(".ui-dialog-title", this).html("Please Login");
    });
	
    $('#div_dialog').parent().parent().width("400px").height("200px");
    
    $('#div_dialog').dialog("open");
    
    $('#username', '#div_dialog').focus();
    
    return;
}

/**
 * Displays the system message
 * @param {String} messageText
 */
function SystemMessage(messageText) {
	
	if (messageText == '' || messageText == null) return;
	
	var message = $('#system_message');

	$('span', message).html(messageText);
	message.dialog('open');
}

/**
 * Toggles the FilterForm view
 */
function ToggleFilterView(div)
{
	if ($('#'+div).css("display") == "none") {
    	$('#'+div).fadeIn("slow");
    }
    else {
    	$('#'+div).fadeOut("slow");
    }
	
	return false;
}