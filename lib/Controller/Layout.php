<?php
/*
 * Xibo - Digital Signage - http://www.xibo.org.uk
 * Copyright (C) 2006-2013 Daniel Garner
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
namespace Xibo\Controller;

use database;
use Kit;
use Media;
use Parsedown;
use Xibo\Entity\Campaign;
use Xibo\Factory\LayoutFactory;
use Xibo\Helper\ApplicationState;
use Xibo\Helper\Config;
use Xibo\Helper\Form;
use Xibo\Helper\Help;
use Xibo\Helper\Sanitize;
use Xibo\Helper\Session;
use Xibo\Helper\Theme;

class Layout extends Base
{
    /**
     * Displays the Layout Page
     */
    function displayPage()
    {
        // Default options
        if (\Kit::IsFilterPinned('layout', 'LayoutFilter')) {

            $layout = Session::Get('layout', 'filter_layout');
            $tags = Session::Get('layout', 'filter_tags');
            $retired = Session::Get('layout', 'filter_retired');
            $owner = Session::Get('layout', 'filter_userid');
            $filterLayoutStatusId = Session::Get('layout', 'filterLayoutStatusId');
            $showDescriptionId = Session::Get('layout', 'showDescriptionId');
            $showThumbnail = Session::Get('layout', 'showThumbnail');
            $showTags = Session::Get('layout', 'showTags');
            $pinned = 1;

        } else {

            $layout = NULL;
            $tags = NULL;
            $retired = 0;
            $owner = NULL;
            $filterLayoutStatusId = 1;
            $showDescriptionId = 2;
            $pinned = 0;
            $showThumbnail = 1;
            $showTags = 0;
        }

        // Users we have permission to see
        $users = $this->getUser()->userList();
        $users = array_map(function($element) { return array('userid' => $element->userId, 'username' => $element->userName); }, $users);
        array_unshift($users, array('userid' => '', 'username' => 'All'));

        $data = [
            'users' => $users,
            'defaults' => [
                'layout' => $layout,
                'tags' => $tags,
                'owner' => $owner,
                'retired' => $retired,
                'filterLayoutStatusId' => $filterLayoutStatusId,
                'showDescriptionId' => $showDescriptionId,
                'showTags' => $showTags,
                'showThumbnail' => $showThumbnail,
                'filterPinned' => $pinned
            ]
        ];

        // Call to render the template
        $this->getState()->template = 'layout-page';
        $this->getState()->setData($data);
    }

    /**
     * Display the Layout Designer
     * @param int $layoutId
     * @throws \ErrorException if the theme file cannot be rendered
     * @throws \Exception if the layout doesn't load correctly
     */
    public function displayDesigner($layoutId)
    {
        $layout = LayoutFactory::loadById($layoutId);

        Theme::Set('layout_form_edit_url', 'index.php?p=layout&q=EditForm&designer=1&layoutid=' . $layoutId);
        Theme::Set('layout_form_savetemplate_url', 'index.php?p=template&q=TemplateForm&layoutid=' . $layoutId);
        Theme::Set('layout_form_addregion_url', 'index.php?p=timeline&q=AddRegion&layoutid=' . $layoutId);
        Theme::Set('layout_form_preview_url', 'index.php?p=preview&q=render&ajax=true&layoutid=' . $layoutId);
        Theme::Set('layout_form_schedulenow_url', 'index.php?p=schedule&q=ScheduleNowForm&CampaignID=' . $layout->campaignId);
        Theme::Set('layout', $layout->layout);
        Theme::Set('layout_designer_editor', $this->RenderDesigner($layout));

        // Set up the theme variables for the Layout Jump List
        Theme::Set('layoutId', $layoutId);
        Theme::Set('layouts', $this->getUser()->LayoutList());

        // Set up any JavaScript translations
        Theme::SetTranslation('save_position_button', __('Save Position'));
        Theme::SetTranslation('revert_position_button', __('Undo'));
        Theme::SetTranslation('savePositionsFirst', Theme::Translate('Please save the pending position changes first by clicking "Save Positions" or cancel by clicking "Undo".'));

        // Call the render the template
        $this->getState()->html .= Theme::RenderReturn('layout_designer');
    }

    /**
     * Add a Layout
     */
    function add()
    {
        $name = Sanitize::getString('layout');
        $description = Sanitize::getString('description');
        $tags = Sanitize::getString('tags');
        $templateId = Sanitize::getInt('templateid');
        $resolutionId = Sanitize::getInt('resolutionid');

        if ($templateId != 0)
            $layout = LayoutFactory::createFromTemplate($templateId, $this->getUser()->userId, $name, $description, $tags);
        else
            $layout = LayoutFactory::createFromResolution($resolutionId, $this->getUser()->userId, $name, $description, $tags);

        // Validate
        $layout->validate();

        // Save
        $layout->save();

        // Add a Campaign
        $campaign = new Campaign();
        $campaign->campaign = $layout->layout;
        $campaign->isLayout = 1;
        $campaign->ownerId = $layout->getOwnerId();
        $campaign->assignLayout($layout->layoutId);

        // Ready to save the Campaign
        $campaign->save();

        // TODO: Set the default permissions on the regions

        // Successful layout creation
        $this->getState()->setData(array('layoutId' => $layout->getId()));
        $this->getState()->SetFormSubmitResponse(__('Layout Details Changed.'), true, $this->urlFor('layoutDesigner', array('id', $layout->layoutId)));
    }

    /**
     * Modifies a layout record
     */
    function modify()
    {


         

        $layout = LayoutFactory::loadById(Kit::GetParam('layoutid', _POST, _INT));

        // Make sure we have permission
        if (!$this->getUser()->checkEditable($layout))
            trigger_error(__('You do not have permissions to edit this layout'), E_USER_ERROR);

        $layout->layout = Sanitize::getString('layout');
        $layout->description = Sanitize::getString('description');
        $layout->tags = \Xibo\Factory\TagFactory::tagsFromString(Kit::GetParam('tags', _POST, _STRING));
        $layout->retired = \Kit::GetParam('retired', _POST, _INT, 0);
        $layout->backgroundColor = Sanitize::getString('backgroundColor');
        $layout->backgroundImageId = Sanitize::getInt('backgroundImageId');
        $layout->backgroundzIndex = Sanitize::getInt('backgroundzIndex');

        // Resolution
        $resolution = \Xibo\Factory\ResolutionFactory::getById(Kit::GetParam('resolutionId', _POST, _INT));
        $layout->width = $resolution->width;
        $layout->height = $resolution->height;

        // Validate
        $layout->validate();

        // Save
        $layout->save();

        if (\Kit::GetParam('designer', _POST, _INT) == 1) {
             $this->getState()->SetFormSubmitResponse(__('Layout Background Changed'), true, sprintf('index.php?p=layout&layoutid=%d&modify=true', $layout->layoutId));
        } else {
             $this->getState()->SetFormSubmitResponse(__('Layout Details Changed.'));
        }

    }

    /**
     * Delete Layout Form
     */
    function DeleteLayoutForm()
    {
         

        $layoutId = Sanitize::getInt('layoutId');
        $layout = LayoutFactory::loadById($layoutId);

        if (!$this->getUser()->checkDeleteable($layout))
            trigger_error(__('You do not have permissions to delete this layout'), E_USER_ERROR);

        Theme::Set('form_id', 'LayoutDeleteForm');
        Theme::Set('form_action', 'index.php?p=layout&q=delete');
        Theme::Set('form_meta', '<input type="hidden" name="layoutId" value="' . $layoutId . '">');
        Theme::Set('form_fields', array(
            Form::AddMessage(__('Are you sure you want to delete this layout?')),
            Form::AddMessage(__('All media will be unassigned and any layout specific media such as text/rss will be lost. The layout will be removed from all Schedules.')),
        ));

        $form = Theme::RenderReturn('form_render');

         $this->getState()->SetFormRequestResponse($form, sprintf(__('Delete %s'), $layout->layout), '300px', '200px');
         $this->getState()->AddButton(__('Help'), 'XiboHelpRender("' . Help::Link('Layout', 'Delete') . '")');
         $this->getState()->AddButton(__('Retire'), 'XiboSwapDialog("index.php?p=layout&q=RetireForm&layoutid=' . $layoutId . '")');
         $this->getState()->AddButton(__('No'), 'XiboDialogClose()');
         $this->getState()->AddButton(__('Yes'), '$("#LayoutDeleteForm").submit()');

    }

    /**
     * Retire Layout Form
     */
    public function RetireForm()
    {
         

        $layoutId = Sanitize::getInt('layoutId');
        $layout = LayoutFactory::loadById(Kit::GetParam('layoutid', _POST, _INT));

        // Make sure we have permission
        if (!$this->getUser()->checkEditable($layout))
            trigger_error(__('You do not have permissions to edit this layout'), E_USER_ERROR);

        Theme::Set('form_id', 'RetireForm');
        Theme::Set('form_meta', '<input type="hidden" name="layoutId" value="' . $layoutId . '">');

        // Retire the layout
        Theme::Set('form_action', 'index.php?p=layout&q=Retire');
        Theme::Set('form_fields', array(Form::AddMessage(__('Are you sure you want to retire this layout ?'))));

        $form = Theme::RenderReturn('form_render');

         $this->getState()->SetFormRequestResponse($form, sprintf(__('Retire %s'), $layout->layout), '300px', '200px');
         $this->getState()->AddButton(__('Help'), 'XiboHelpRender("' . Help::Link('Layout', 'Retire') . '")');
         $this->getState()->AddButton(__('Delete'), 'XiboSwapDialog("index.php?p=layout&q=DeleteLayoutForm&layoutid=' . $layoutId . '")');
         $this->getState()->AddButton(__('No'), 'XiboDialogClose()');
         $this->getState()->AddButton(__('Yes'), '$("#RetireForm").submit()');

    }

    /**
     * Deletes a layout record from the DB
     */
    function delete()
    {
        $layoutId = Sanitize::getInt('layoutId');

        $layout = LayoutFactory::loadById($layoutId);

        if (!$this->getUser()->checkDeleteable($layout))
            trigger_error(__('You do not have permissions to delete this layout'), E_USER_ERROR);

        $layout->delete();

        $this->getState()->SetFormSubmitResponse(__('The Layout has been Deleted'));
    }

    /**
     * Retires a layout
     */
    function Retire()
    {


         
        $layoutId = \Kit::GetParam('layoutid', _POST, _INT, 0);

        if (!$this->auth->edit)
            trigger_error(__('You do not have permission to retire this layout'), E_USER_ERROR);

        $layoutObject = new Layout();

        if (!$layoutObject->Retire($layoutId))
            trigger_error($layoutObject->GetErrorMessage(), E_USER_ERROR);

         $this->getState()->SetFormSubmitResponse(__('The Layout has been Retired'));

    }

    /**
     * Shows the Layout Grid
     */
    function LayoutGrid()
    {
        $this->getState()->template = 'grid';

        // Filter by Name
        $name = Sanitize::getString('filter_layout');
        Session::Set('layout', 'filter_layout', $name);

        // User ID
        $filter_userid = Sanitize::getInt('filter_userid');
        Session::Set('layout', 'filter_userid', $filter_userid);

        // Show retired
        $filter_retired = Sanitize::getInt('filter_retired');
        Session::Set('layout', 'filter_retired', $filter_retired);

        // Show filterLayoutStatusId
        $filterLayoutStatusId = Sanitize::getInt('filterLayoutStatusId');
        Session::Set('layout', 'filterLayoutStatusId', $filterLayoutStatusId);

        // Show showDescriptionId
        $showDescriptionId = Sanitize::getInt('showDescriptionId');
        Session::Set('layout', 'showDescriptionId', $showDescriptionId);

        // Show filter_showThumbnail
        $showTags = Sanitize::getCheckbox('showTags');
        Session::Set('layout', 'showTags', $showTags);

        // Show filter_showThumbnail
        $showThumbnail = Sanitize::getCheckbox('showThumbnail');
        Session::Set('layout', 'showThumbnail', $showThumbnail);

        // Tags list
        $filter_tags = \Kit::GetParam("filter_tags", _POST, _STRING);
        Session::Set('layout', 'filter_tags', $filter_tags);

        // Pinned option?
        Session::Set('layout', 'LayoutFilter', \Kit::GetParam('XiboFilterPinned', _REQUEST, _CHECKBOX, 'off'));

        // Get all layouts
        $layouts = $this->getUser()->LayoutList($this->gridRenderSort(), array(
            'layout' => $name,
            'userId' => $filter_userid,
            'retired' => $filter_retired,
            'tags' => $filter_tags,
            'filterLayoutStatusId' => $filterLayoutStatusId,
            'showTags' => $showTags));

        if (!is_array($layouts))
            trigger_error(__('Unable to get layouts for user'), E_USER_ERROR);

        $rows = array();

        foreach ($layouts as $layout) {
            /* @var \Xibo\Entity\Layout $layout */
            // Construct an object containing all the layouts, and pass to the theme
            $row = array();

            $row['layoutid'] = $layout->layoutId;
            $row['layout'] = $layout->layout;
            $row['description'] = $layout->description;
            $row['tags'] = $layout->tags;
            $row['owner'] = $layout->owner;
            $row['permissions'] = $layout->groupsWithPermissions;

            $row['thumbnail'] = '';

            if ($showThumbnail == 1 && $layout->backgroundImageId != 0)
                $row['thumbnail'] = '<a class="img-replace" data-toggle="lightbox" data-type="image" data-img-src="index.php?p=content&q=getFile&mediaid=' . $layout->backgroundImageId . '&width=100&height=100&dynamic=true&thumb=true" href="index.php?p=content&q=getFile&mediaid=' . $layout->backgroundImageId . '"><i class="fa fa-file-image-o"></i></a>';

            // Fix up the description
            if ($showDescriptionId == 1) {
                // Parse down for description
                $row['descriptionWithMarkdown'] = Parsedown::instance()->text($row['description']);
            } else if ($showDescriptionId == 2) {
                $row['description'] = strtok($row['description'], "\n");
            }

            switch ($layout->status) {

                case 1:
                    $row['status'] = 1;
                    $row['statusDescription'] = __('This Layout is ready to play');
                    break;

                case 2:
                    $row['status'] = 2;
                    $row['statusDescription'] = __('There are items on this Layout that can only be assessed by the Display');
                    break;

                case 3:
                    $row['status'] = 0;
                    $row['statusDescription'] = __('This Layout is invalid and should not be scheduled');
                    break;

                default:
                    $row['status'] = 0;
                    $row['statusDescription'] = __('The Status of this Layout is not known');
            }


            $row['layout_form_edit_url'] = 'index.php?p=layout&q=displayForm&layoutid=' . $layout->layoutId;

            // Add some buttons for this row
            if ($this->getUser()->checkEditable($layout)) {
                // Design Button
                $row['buttons'][] = array(
                    'id' => 'layout_button_design',
                    'linkType' => '_self',
                    'url' => $this->app->urlFor('layoutUpdate', array('id' => $layout->layoutId)),
                    'text' => __('Design')
                );
            }

            // Preview
            $row['buttons'][] = array(
                'id' => 'layout_button_preview',
                'linkType' => '_blank',
                'url' => 'index.php?p=preview&q=render&ajax=true&layoutid=' . $layout->layoutId,
                'text' => __('Preview Layout')
            );

            // Schedule Now
            $row['buttons'][] = array(
                'id' => 'layout_button_schedulenow',
                'url' => 'index.php?p=schedule&q=ScheduleNowForm&CampaignID=' . $layout->campaignId,
                'text' => __('Schedule Now')
            );

            $row['buttons'][] = ['divider' => true];

            // Only proceed if we have edit permissions
            if ($this->getUser()->checkEditable($layout)) {

                // Edit Button
                $row['buttons'][] = array(
                    'id' => 'layout_button_edit',
                    'url' => 'index.php?p=layout&q=EditForm&layoutid=' . $layout->layoutId,
                    'text' => __('Edit')
                );

                // Copy Button
                $row['buttons'][] = array(
                    'id' => 'layout_button_copy',
                    'url' => 'index.php?p=layout&q=CopyForm&layoutid=' . $layout->layoutId . '&oldlayout=' . urlencode($layout->layout),
                    'text' => __('Copy')
                );

                // Retire Button
                $row['buttons'][] = array(
                    'id' => 'layout_button_retire',
                    'url' => 'index.php?p=layout&q=RetireForm&layoutId=' . $layout->layoutId,
                    'text' => __('Retire'),
                    'multi-select' => true,
                    'dataAttributes' => array(
                        array('name' => 'multiselectlink', 'value' => 'index.php?p=layout&q=Retire'),
                        array('name' => 'rowtitle', 'value' => $row['layout']),
                        array('name' => 'layoutid', 'value' => $layout->layoutId)
                    )
                );

                // Extra buttons if have delete permissions
                if ($this->getUser()->checkDeleteable($layout)) {
                    // Delete Button
                    $row['buttons'][] = array(
                        'id' => 'layout_button_delete',
                        'url' => 'index.php?p=layout&q=DeleteLayoutForm&layoutId=' . $layout->layoutId,
                        'text' => __('Delete'),
                        'multi-select' => true,
                        'dataAttributes' => array(
                            array('name' => 'multiselectlink', 'value' => 'index.php?p=layout&q=delete'),
                            array('name' => 'rowtitle', 'value' => $row['layout']),
                            array('name' => 'layoutid', 'value' => $layout->layoutId)
                        )
                    );
                }

                $row['buttons'][] = ['divider' => true];

                // Export Button
                $row['buttons'][] = array(
                    'id' => 'layout_button_export',
                    'linkType' => '_self',
                    'url' => 'index.php?p=layout&q=Export&layoutid=' . $layout->layoutId,
                    'text' => __('Export')
                );

                // Extra buttons if we have modify permissions
                if ($this->getUser()->checkPermissionsModifyable($layout)) {
                    // Permissions button
                    $row['buttons'][] = array(
                        'id' => 'layout_button_permissions',
                        'url' => 'index.php?p=user&q=permissionsForm&entity=Campaign&objectId=' . $layout->campaignId,
                        'text' => __('Permissions')
                    );
                }
            }

            // Add the row
            $rows[] = $row;
        }

        // Store the table rows
        $this->getState()->setData($rows);
    }

    /**
     * Displays an Add/Edit form
     */
    function AddForm()
    {
        Theme::Set('form_id', 'LayoutForm');

        // Two tabs
        $tabs = array();
        $tabs[] = Form::AddTab('general', __('General'));
        $tabs[] = Form::AddTab('description', __('Description'));

        Theme::Set('form_tabs', $tabs);

        $formFields = array();
        $formFields['general'][] = Form::AddText('layout', __('Name'), (isset($layout['layout']) ? $layout['layout'] : NULL), __('The Name of the Layout - (1 - 50 characters)'), 'n', 'required');
        $formFields['general'][] = Form::AddText('tags', __('Tags'), (isset($layout['tags']) ? $layout['tags'] : NULL), __('Tags for this layout - used when searching for it. Comma delimited. (1 - 250 characters)'), 't', 'maxlength="250"');

        $formFields['description'][] = Form::AddMultiText('description', __('Description'), (isset($layout['description']) ? $layout['description'] : NULL),
            __('An optional description of the Layout. (1 - 250 characters)'), 'd', 5, 'maxlength="250"');

        // We are adding
        Theme::Set('form_action', $this->urlFor('layoutAdd'));

        $templates = $this->getUser()->TemplateList();
        $templates = array_map(function($element) { return array('layoutid' => $element->layoutId, 'layout' => $element->layout); }, $templates);
        array_unshift($templates, array('layoutid' => '0', 'layout' => 'None'));

        $formFields['general'][] = Form::AddCombo(
            'templateid',
            __('Template'),
            NULL,
            $templates,
            'layoutid',
            'layout',
            __('Optionally choose a template you have saved before.'),
            't');

        $formFields['general'][] = Form::AddCombo(
            'resolutionid',
            __('Resolution'),
            NULL,
            $this->getUser()->ResolutionList(),
            'resolutionId',
            'resolution',
            __('Choose the resolution this Layout should be designed for.'),
            'r',
            'resolution-group');

         $this->getState()->AddFieldAction('templateid', 'change', 0, array('.resolution-group' => array('display' => 'block')));
         $this->getState()->AddFieldAction('templateid', 'change', 0, array('.resolution-group' => array('display' => 'none')), "not");

        Theme::Set('form_fields_general', $formFields['general']);
        Theme::Set('form_fields_description', $formFields['description']);


         $this->getState()->SetFormRequestResponse(null, __('Add Layout'), '350px', '275px');
         $this->getState()->AddButton(__('Help'), 'XiboHelpRender("' . Help::Link('Layout', 'Add') . '")');
         $this->getState()->AddButton(__('Cancel'), 'XiboDialogClose()');
         $this->getState()->AddButton(__('Save'), '$("#LayoutForm").submit()');

    }

    /**
     * Edit form
     */
    function EditForm()
    {
         
        $layoutId = Sanitize::getInt('layoutid');

        // Get the layout
        $layout = LayoutFactory::getById($layoutId);

        // Check Permissions
        if (!$this->getUser()->checkEditable($layout))
            trigger_error(__('You do not have permissions to edit this layout'), E_USER_ERROR);

        // Generate the form
        Theme::Set('form_id', 'LayoutForm');
        Theme::Set('form_action', 'index.php?p=layout&q=modify');
        Theme::Set('form_meta', '<input type="hidden" name="layoutid" value="' . $layoutId . '"><input type="hidden" name="designer" value="' . \Kit::GetParam('designer', _GET, _INT) . '">');

        // Two tabs
        $tabs = array();
        $tabs[] = Form::AddTab('general', __('General'));
        $tabs[] = Form::AddTab('description', __('Description'));
        $tabs[] = Form::AddTab('background', __('Background'));

        Theme::Set('form_tabs', $tabs);

        $formFields = array();
        $formFields['general'][] = Form::AddText('layout', __('Name'), $layout->layout, __('The Name of the Layout - (1 - 50 characters)'), 'n', 'required');
        $formFields['general'][] = Form::AddText('tags', __('Tags'), $layout->tags, __('Tags for this layout - used when searching for it. Comma delimited. (1 - 250 characters)'), 't', 'maxlength="250"');

        $formFields['description'][] = Form::AddMultiText('description', __('Description'), $layout->description,
            __('An optional description of the Layout. (1 - 250 characters)'), 'd', 5, 'maxlength="250"');

        $formFields['general'][] = Form::AddCombo(
            'retired',
            __('Retired'),
            $layout->retired,
            array(array('retiredid' => '1', 'retired' => 'Yes'), array('retiredid' => '0', 'retired' => 'No')),
            'retiredid',
            'retired',
            __('Retire this layout or not? It will no longer be visible in lists'),
            'r');

        Theme::Set('form_fields_general', $formFields['general']);
        Theme::Set('form_fields_description', $formFields['description']);

        // Background Tab
        // Do we need to override the background with one passed in?
        $backgroundImageId = \Kit::GetParam('backgroundOveride', _GET, _INT, $layout->backgroundImageId);

        // Manipulate the images slightly
        $thumbBgImage = ($backgroundImageId == 0) ? 'theme/default/img/forms/filenotfound.gif' : 'index.php?p=module&mod=image&q=Exec&method=GetResource&mediaid=' . $backgroundImageId . '&width=200&height=200&dynamic';

        // Get the ID of the current resolution
        $resolution = \Xibo\Factory\ResolutionFactory::getByDimensions($layout->width, $layout->height);

        // A list of web safe colours
        $formFields['background'][] = Form::AddText('backgroundColor', __('Background Colour'), $layout->backgroundColor,
            __('Use the colour picker to select the background colour'), 'c', 'required');

        // A list of available backgrounds
        $backgrounds = $this->getUser()->MediaList(NULL, array('type' => 'image'));
        $backgrounds = array_map(function ($element) {
            /* @var \Xibo\Entity\Media $element */
            return array('mediaid' => $element->mediaId, 'media' => $element->name);
        }, $backgrounds);
        array_unshift($backgrounds, array('mediaid' => '0', 'media' => 'None'));

        $formFields['background'][] = Form::AddCombo(
            'backgroundImageId',
            __('Background Image'),
            $backgroundImageId,
            $backgrounds,
            'mediaid',
            'media',
            __('Pick the background image from the library'),
            'b', '', true, 'onchange="background_button_callback()"');

        $formFields['background'][] = Form::AddCombo(
            'resolutionId',
            __('Resolution'),
            $resolution->resolutionId,
            $this->getUser()->ResolutionList(NULL, array('withCurrent' => $resolution->resolutionId)),
            'resolutionId',
            'resolution',
            __('Change the resolution'),
            'r');

        $formFields['background'][] = Form::AddNumber('backgroundzIndex', __('Layer'), $layout->backgroundzIndex,
            __('The layering order of the background image (z-index). Advanced use only. '), 'z');

        $formFields['background'][] = Form::AddRaw('<img id="bg_image_image" src="' . $thumbBgImage . '" alt="' . __('Background thumbnail') . '" />');

        Theme::Set('form_fields_background', $formFields['background']);

         $this->getState()->SetFormRequestResponse(null, __('Edit Layout'));
         $this->getState()->callBack = 'backGroundFormSetup';
         $this->getState()->AddButton(__('Help'), 'XiboHelpRender("' . Help::Link('Layout', 'Edit') . '")');
         $this->getState()->AddButton(__('Add Image'), 'XiboFormRender("index.php?p=module&q=Exec&mod=image&method=AddForm&backgroundImage=true&layoutid=' . $layout->layoutId . '")');
         $this->getState()->AddButton(__('Cancel'), 'XiboDialogClose()');
         $this->getState()->AddButton(__('Save'), '$("#LayoutForm").submit()');

    }

    /**
     * Render the designer
     * @param \Xibo\Entity\Layout $layout
     * @return string
     */
    function RenderDesigner($layout)
    {
        // What zoom level are we at?
        $zoom = Sanitize::getDouble('zoom', 1);

        // Get the width and the height
        $version = $layout->schemaVersion;
        Theme::Set('layoutVersion', $version);
        Theme::Set('layout', $layout->layout);

        // Get the display width / height
        // Version 1 layouts had the designer resolution in the XLF and therefore did not need anything scaling in the designer.
        // Version 2+ layouts have the layout resolution in the XLF and therefore need to be scaled back by the designer.
        if ($layout->width < $layout->height) {
            // Portrait
            $displayWidth = 800;
            $designerScale = $displayWidth / $layout->width;
        } else {
            // Landscape
            $displayHeight = 450;
            $designerScale = $displayHeight / $layout->height;
        }

        // Version 2 layout can support zooming?
        if ($version > 1) {
            $designerScale = $designerScale * $zoom;

            Theme::Set('layout_zoom_in_url', 'index.php?p=layout&modify=true&layoutid=' . $layout->layoutId . '&zoom=' . ($zoom - 0.3));
            Theme::Set('layout_zoom_out_url', 'index.php?p=layout&modify=true&layoutid=' . $layout->layoutId . '&zoom=' . ($zoom + 0.3));
        } else {
            Theme::Set('layout_upgrade_url', 'index.php?p=layout&q=upgradeForm&layoutId=' . $layout->layoutId);
        }

        // Pass the designer scale to the theme (we use this to present an error message in the default theme, if the scale drops below 0.41)
        Theme::Set('designerScale', $designerScale);

        // Reset the designer width / height based on the zoom
        $width = ($layout->width * $designerScale) . "px";
        $height = ($layout->height * $designerScale) . "px";

        // Background CSS
        $backgroundCss = ($layout->backgroundImageId == 0) ? $layout->backgroundColor : 'url(\'index.php?p=content&q=getFile&mediaid=' . $layout->backgroundImageId . '&width=' . $width . '&height=' . $height . '&dynamic&proportional=0\') top center no-repeat; background-color:' . $layout->backgroundColor;

        // Get all the regions and draw them on
        $regionHtml = '';

        //get the regions
        foreach ($layout->regions as $region) {
            /* @var \Xibo\Entity\Region $region */
            // Get dimensions
            $tipWidth = round($region->width, 0);
            $tipHeight = round($region->height, 0);
            $tipLeft = round($region->left, 0);
            $tipTop = round($region->top, 0);

            $regionWidth = ($region->width * $designerScale) . 'px';
            $regionHeight = ($region->height * $designerScale) . 'px';
            $regionLeft = ($region->left * $designerScale) . 'px';
            $regionTop = ($region->top * $designerScale) . 'px';

            $regionZindex = ($region->zIndex == 0) ? '' : 'zindex="' . $region->zIndex . '"';
            $styleZindex = ($region->zIndex == 0) ? '' : 'z-index: ' . $region->zIndex . ';';

            // Permissions
            $regionAuth = $this->getUser()->getPermission($region);

            $paddingTop = ($regionHeight / 2 - 16) . 'px';

            $regionAuthTransparency = ($regionAuth->edit) ? '' : ' regionDisabled';
            $regionDisabledClass = ($regionAuth->edit) ? 'region' : 'regionDis';
            $regionPreviewClass = ($regionAuth->view) ? 'regionPreview' : '';

            $regionTransparency = '<div class="regionTransparency ' . $regionAuthTransparency . '" style="width:100%; height:100%;"></div>';
            $doubleClickLink = ($regionAuth->edit) ? "XiboFormRender($(this).attr('href'))" : '';

            $regionHtml .= '<div id="region_' . $region->regionId . '" regionEnabled="' . $regionAuth->edit . '" regionid="' . $region->regionId . '"';
            $regionHtml .= ' layoutid="' . $layout->layoutId . '" ' . $regionZindex . ' tip_scale="1" designer_scale="' . $designerScale . '"';
            $regionHtml .= ' width="' . $regionWidth . '" height="' . $regionHeight . '" href="index.php?p=timeline&layoutid=' . $layout->layoutId . '&regionid=' . $region->regionId . '&q=Timeline"';
            $regionHtml .= ' ondblclick="' . $doubleClickLink . '" class="' . $regionDisabledClass . ' ' . $regionPreviewClass . '" style="position:absolute; width:' . $regionWidth . '; height:' . $regionHeight . '; top: ';
            $regionHtml .= $regionTop . '; left: ' . $regionLeft . '; ' . $styleZindex . '">' . $regionTransparency;

            if ($regionAuth->edit) {

                $regionHtml .= '<div class="btn-group regionInfo pull-right">';
                $regionHtml .= '    <button class="btn dropdown-toggle" data-toggle="dropdown">';
                $regionHtml .= '<span class="region-tip">' . $tipWidth . ' x ' . $tipHeight . ' (' . $tipLeft . ',' . $tipTop . ')' . '</span>';
                $regionHtml .= '        <span class="caret"></span>';
                $regionHtml .= '    </button>';
                $regionHtml .= '    <ul class="dropdown-menu">';
                $regionHtml .= '        <li><a class="XiboFormButton" href="index.php?p=timeline&q=Timeline&layoutid=' . $layout->layoutId . '&regionid=' . $region->regionId . '" title="' . __('Timeline') . '">' . __('Edit Timeline') . '</a></li>';
                $regionHtml .= '        <li><a class="RegionOptionsMenuItem" href="#" title="' . __('Options') . '">' . __('Options') . '</a></li>';
                $regionHtml .= '        <li><a class="XiboFormButton" href="index.php?p=timeline&q=DeleteRegionForm&layoutid=' . $layout->layoutId . '&regionid=' . $region->regionId . '" title="' . __('Delete') . '">' . __('Delete') . '</a></li>';
                $regionHtml .= '        <li><a class="XiboFormButton" href="index.php?p=user&q=permissionsForm&entity=Region&objectId=' . $region->regionId . '" title="' . __('Permissions') . '">' . __('Permissions') . '</a></li>';
                $regionHtml .= '    </ul>';
                $regionHtml .= '</div>';

            } else if ($regionAuth->view) {
                $regionHtml .= '<div class="regionInfo">';
                $regionHtml .= '<span class="region-tip">' . $tipWidth . ' x ' . $tipHeight . ' (' . $tipLeft . ',' . $tipTop . ')' . '</span>';
                $regionHtml .= '</div>';
            }

            $regionHtml .= '    <div class="preview">';
            $regionHtml .= '        <div class="previewContent"></div>';
            $regionHtml .= '        <div class="previewNav label label-info"></div>';
            $regionHtml .= '    </div>';
            $regionHtml .= '</div>';
        }

        $statusUrl = $this->urlFor('layoutStatus', array('id' => $layout->layoutId));

        //render the view pane
        $surface = <<<HTML
        <div id="layout" zoom="$zoom" tip_scale="1" designer_scale="$designerScale" class="layout" layoutid="{$layout->layoutId}" data-background-color="{$layout->backgroundColor}" data-status-url="{$statusUrl}" style="position:relative; width:$width; height:$height; background:$backgroundCss;">
        $regionHtml
        </div>
HTML;

        return $surface;
    }

    /**
     * Copy layout form
     */
    public function CopyForm()
    {
         

        $layoutId = Sanitize::getInt('layoutid');

        // Get the layout
        $layout = LayoutFactory::getById($layoutId);

        // Check Permissions
        if (!$this->getUser()->checkViewable($layout))
            trigger_error(__('You do not have permissions to view this layout'), E_USER_ERROR);

        $copyMediaChecked = (Config::GetSetting('LAYOUT_COPY_MEDIA_CHECKB') == 'Checked') ? 1 : 0;

        Theme::Set('form_id', 'LayoutCopyForm');
        Theme::Set('form_action', 'index.php?p=layout&q=Copy');
        Theme::Set('form_meta', '<input type="hidden" name="layoutid" value="' . $layout->layoutId . '">');

        $formFields = array();
        $formFields[] = Form::AddText('layout', __('Name'), $layout->layout . ' 2', __('The Name of the Layout - (1 - 50 characters)'), 'n', 'required');
        $formFields[] = Form::AddText('description', __('Description'), $layout->description, __('An optional description of the Layout. (1 - 250 characters)'), 'd', 'maxlength="250"');
        $formFields[] = Form::AddCheckbox('copyMediaFiles', __('Make new copies of all media on this layout?'), $copyMediaChecked,
            __('This will duplicate all media that is currently assigned to the Layout being copied.'), 'c');

        Theme::Set('form_fields', $formFields);

        $form = Theme::RenderReturn('form_render');

         $this->getState()->SetFormRequestResponse($form, sprintf(__('Copy %s'), $layout->layout), '350px', '275px');
         $this->getState()->AddButton(__('Help'), 'XiboHelpRender("' . Help::Link('Layout', 'Copy') . '")');
         $this->getState()->AddButton(__('Cancel'), 'XiboDialogClose()');
         $this->getState()->AddButton(__('Copy'), '$("#LayoutCopyForm").submit()');

    }

    /**
     * Copies a layout
     */
    public function Copy()
    {


         

        // Load the layout for Copy
        $layout = clone LayoutFactory::loadById(Kit::GetParam('layoutid', _POST, _INT));

        $layout->layout = Sanitize::getString('layout');
        $layout->description = Sanitize::getString('description');

        // Validate the new layout
        $layout->validate();

        // TODO: Copy the media on the layout and change the assignments.
        if (\Kit::GetParam('copyMediaFiles', _POST, _CHECKBOX == 1)) {

        }

        // Save the new layout
        $layout->save();

         $this->getState()->SetFormSubmitResponse(__('Layout Copied'));

    }

    public function LayoutStatus()
    {

         
        $layoutId = Sanitize::getInt('layoutId');


        $layout = new Layout($db);

        $status = "";

        switch ($layout->IsValid($layoutId)) {

            case 1:
                $status = '<span title="' . __('This Layout is ready to play') . '" class="glyphicon glyphicon-ok-circle"></span>';
                break;

            case 2:
                $status = '<span title="' . __('There are items on this Layout that can only be assessed by the client') . '" class="glyphicon glyphicon-question-sign"></span>';
                break;

            case 3:
                $status = '<span title="' . __('This Layout is invalid and should not be scheduled') . '" class="glyphicon glyphicon-remove-sign"></span>';
                break;

            default:
                $status = '<span title="' . __('The Status of this Layout is not known') . '" class="glyphicon glyphicon-warning-sign"></span>';
        }

        // Keep things tidy
        // Maintenance should also do this.
        Media::removeExpiredFiles();

         $this->getState()->html = $status;
         $this->getState()->success = true;

    }

    public function Export()
    {

        $layoutId = Sanitize::getInt('layoutid');


        $layout = new Layout($this->db);

        if (!$layout->Export($layoutId)) {
            trigger_error($layout->GetErrorMessage(), E_USER_ERROR);
        }

        exit;
    }

    public function ImportForm()
    {
        global $session;

         

        // Set the Session / Security information
        $sessionId = session_id();
        $securityToken = CreateFormToken();

        $session->setSecurityToken($securityToken);

        // Find the max file size
        $maxFileSizeBytes = convertBytes(ini_get('upload_max_filesize'));

        // Set some information about the form
        Theme::Set('form_id', 'LayoutImportForm');
        Theme::Set('form_action', 'index.php?p=layout&q=Import');
        Theme::Set('form_meta', '<input type="hidden" id="txtFileName" name="txtFileName" readonly="true" /><input type="hidden" name="hidFileID" id="hidFileID" value="" /><input type="hidden" name="template" value="' . \Kit::GetParam('template', _GET, _STRING, 'false') . '" />');

        Theme::Set('form_upload_id', 'file_upload');
        Theme::Set('form_upload_action', 'index.php?p=content&q=FileUpload');
        Theme::Set('form_upload_meta', '<input type="hidden" id="PHPSESSID" value="' . $sessionId . '" /><input type="hidden" id="SecurityToken" value="' . $securityToken . '" /><input type="hidden" name="MAX_FILE_SIZE" value="' . $maxFileSizeBytes . '" />');

        Theme::Set('prepend', Theme::RenderReturn('form_file_upload_single'));

        $formFields = array();
        $formFields[] = Form::AddText('layout', __('Name'), NULL, __('The Name of the Layout - (1 - 50 characters). Leave blank to use the name from the import.'), 'n');
        $formFields[] = Form::AddCheckbox('replaceExisting', __('Replace Existing Media?'),
            NULL,
            __('If the import finds existing media with the same name, should it be replaced in the Layout or should the Layout use that media.'),
            'r');

        if (\Kit::GetParam('template', _GET, _STRING, 'false') != 'true')
            $formFields[] = Form::AddCheckbox('importTags', __('Import Tags?'),
                NULL,
                __('Would you like to import any tags contained on the layout.'),
                't');

        Theme::Set('form_fields', $formFields);

         $this->getState()->SetFormRequestResponse(NULL, __('Import Layout'), '350px', '200px');
         $this->getState()->AddButton(__('Help'), 'XiboHelpRender("' . Help::Link('DataSet', 'ImportCsv') . '")');
         $this->getState()->AddButton(__('Cancel'), 'XiboDialogClose()');
         $this->getState()->AddButton(__('Import'), '$("#LayoutImportForm").submit()');

    }

    public function Import()
    {


         

        // What are we importing?
        $template = \Kit::GetParam('template', _POST, _STRING, 'false');
        $template = ($template == 'true');

        $layout = Sanitize::getString('layout');
        $replaceExisting = Sanitize::getCheckbox('replaceExisting');
        $importTags = \Kit::GetParam('importTags', _POST, _CHECKBOX, (!$template));

        // File data
        $tmpName = Sanitize::getString('hidFileID');

        if ($tmpName == '')
            trigger_error(__('Please ensure you have picked a file and it has finished uploading'), E_USER_ERROR);

        // File name and extension (original name)
        $fileName = Sanitize::getString('txtFileName');
        $fileName = basename($fileName);
        $ext = strtolower(substr(strrchr($fileName, "."), 1));

        // File upload directory.. get this from the settings object
        $fileLocation = Config::GetSetting('LIBRARY_LOCATION') . 'temp/' . $tmpName;


        $layoutObject = new Layout($this->db);

        if (!$layoutObject->Import($fileLocation, $layout, $this->getUser()->userId, $template, $replaceExisting, $importTags)) {
            trigger_error($layoutObject->GetErrorMessage(), E_USER_ERROR);
        }

         $this->getState()->SetFormSubmitResponse(__('Layout Imported'));

    }

    public function ShowXlf()
    {
         

        $layout = LayoutFactory::loadById(Kit::GetParam('layoutId', _GET, _INT));

         $this->getState()->SetFormRequestResponse('<pre>' . json_format(json_encode($layout)) . '</pre>', 'Test', '350px', '200px');
         $this->getState()->dialogClass = 'modal-big';

    }
}

?>