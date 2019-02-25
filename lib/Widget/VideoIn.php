<?php
/**
 * Xibo - Digital Signage - http://www.xibo.org.uk
 * Copyright (C) 2017-2018 Xibo Signage Ltd.
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
namespace Xibo\Widget;

use Respect\Validation\Validator as v;
use Xibo\Exception\InvalidArgumentException;

/**
 * Class VideoIn
 * @package Xibo\Widget
 */
class VideoIn extends ModuleWidget
{

    /**
     * Javascript functions for the layout designer
     */
    public function layoutDesignerJavaScript()
    {
        return 'videoin-designer-javascript';
    }

    /** @inheritdoc */
    public function installOrUpdate($moduleFactory)
    {
        if ($this->module == null) {
            // Install
            $module = $moduleFactory->createEmpty();
            $module->name = 'Video In';
            $module->type = 'videoin';
            $module->class = 'Xibo\Widget\VideoIn';
            $module->description = 'A module for displaying Video and Audio from an external source';
            $module->enabled = 1;
            $module->previewEnabled = 0;
            $module->assignable = 1;
            $module->regionSpecific = 1;
            $module->renderAs = 'native';
            $module->schemaVersion = $this->codeSchemaVersion;
            $module->defaultDuration = 60;
            $module->settings = [];
            $module->installName = 'videoin';

            $this->setModule($module);
            $this->installModule();
        }

        // Check we are all installed
        $this->installFiles();
    }

    /**
     * Edit
     *
     * @SWG\Put(
     *  path="/playlist/widget/{widgetId}",
     *  operationId="WidgetVideoInEdit",
     *  tags={"widget"},
     *  summary="Edit a Video In Widget",
     *  description="Edit a Video In Widget",
     *  @SWG\Parameter(
     *      name="widgetId",
     *      in="path",
     *      description="The WidgetId to Edit",
     *      type="integer",
     *      required=true
     *   ),
     *  @SWG\Parameter(
     *      name="duration",
     *      in="formData",
     *      description="The Widget Duration",
     *      type="integer",
     *      required=false
     *  ),
     *  @SWG\Parameter(
     *      name="useDuration",
     *      in="formData",
     *      description="Flag (0, 1) Select only if you will provide duration parameter as well",
     *      type="integer",
     *      required=false
     *  ),
     *  @SWG\Parameter(
     *      name="sourceId",
     *      in="formData",
     *      description="Which device input should be shown? available options: HDMI, RGB, DVI, DP, OPS",
     *      type="string",
     *      required=true
     *   ),
     *  @SWG\Response(
     *      response=201,
     *      description="successful operation"
     *  )
     * )
     *
     * @throws \Xibo\Exception\XiboException
     */
    public function edit()
    {
        // Set some options
        $this->setDuration($this->getSanitizer()->getInt('duration', $this->getDuration()));
        $this->setUseDuration($this->getSanitizer()->getCheckbox('useDuration'));
        $this->setOption('sourceId', $this->getSanitizer()->getString('sourceId' ,'hdmi'));
        $this->setOption('enableStat', $this->getSanitizer()->getString('enableStat'));

        $this->isValid();

        // Save the widget
        $this->saveWidget();
    }

    /** @inheritdoc */
    public function isValid()
    {
        if (!v::stringType()->notEmpty()->validate($this->getOption('sourceId')))
            throw new InvalidArgumentException(__('Please Select the sourceId'), 'sourceId');

        if ($this->getUseDuration() == 1 && !v::intType()->min(1)->validate($this->getDuration()))
            throw new InvalidArgumentException(__('You must enter a duration.'), 'duration');

        // Client dependant
        return self::$STATUS_PLAYER;
    }

    /** @inheritdoc */
    public function hoverPreview()
    {
        // Default Hover window contains a thumbnail, media type and duration
        $output = '<div class="well">';
        $output .= '<div class="preview-module-image"><i alt="' . __($this->module->name) . ' thumbnail" class="fa module-preview-icon module-icon-' . __($this->module->type) . '"></i></div>';
        $output .= '<div class="info">';
        $output .= '    <ul>';
        $output .= '    <li>' . __('Type') . ': ' . $this->module->name . '</li>';
        $output .= '    <li>' . __('Name') . ': ' . $this->getName() . '</li>';
        $output .= '    <li>' . __('Input') . ': ' . $this->getOption('sourceId') . '</li>';
        if ($this->getUseDuration() == 1)
            $output .= '    <li>' . __('Duration') . ': ' . $this->widget->duration . ' ' . __('seconds') . '</li>';
        $output .= '    </ul>';
        $output .= '</div>';
        $output .= '</div>';

        return $output;

    }

    /** @inheritdoc */
    public function getResource($displayId)
    {
        // Get resource isn't required for this module
        return null;
    }
}
