<?php

/**
 * ProcessWire module to restrict multi-language by branch.
 * by Adrian Jones
 *
 * ProcessWire 3.x
 * Copyright (C) 2011 by Ryan Cramer
 * Licensed under GNU/GPL v2, see LICENSE.TXT
 *
 * http://www.processwire.com
 * http://www.ryancramer.com
 *
 */

class RestrictMultiLanguageBranch extends WireData implements Module, ConfigurableModule {

	/**
	 * Basic information about module
	 */
	public static function getModuleInfo() {
		return array(
			'title' => 'Restrict Multi-Language Branch',
			'summary' => 'Restrict multi-language by branch.',
            'author' => 'Adrian Jones',
			'href' => 'https://processwire.com/talk/topic/14891-restrict-multi-language-branch/',
			'version' => '0.1.2',
			'permanent' => false,
            'autoload' => "template=admin",
			'singular' => true,
            'icon' => 'language'
		);
	}


    /**
     * Data as used by the get/set functions
     *
     */
    protected $data = array();
    private $closestSpecifiedParent = null;
    private $closestMatch = null;

    protected $multilanguageRestrictOptions = array(
        "pid" => '',
        "multilanguageStatus" => 0,
        "onlyThisPage" => 0
    );


   /**
     * Default configuration for module
     *
     */
    static public function getDefaultData() {
        return array(
            "multilanguageDefaultStatus" => 'enabled'
        );
    }


    /**
     * Populate the default config data
     *
     */
    public function __construct() {
       foreach(self::getDefaultData() as $key => $value) {
               $this->$key = $value;
       }
    }


	/**
	 * Initialize the module and setup hooks
	 */
	public function init() {

        if($this->wire('user')->isSuperuser()) {
            $this->wire('config')->styles->add($this->wire('config')->urls->RestrictMultiLanguageBranch . "RestrictMultiLanguageBranch.css");
            $this->addHookAfter('ProcessPageEdit::buildFormSettings', $this, 'buildMultilanguageStatusForm');
            $this->addHookAfter('ProcessPageEdit::processInput', $this, 'processMultilanguageStatusForm');
        }

        if(empty($this->data['multilanguageStatus']) && $this->data['multilanguageDefaultStatus'] != 'disabled') return; // no page restrictions defined so can exit now

        // if we get this far then add hook on page edit to apply status restrictions
        $this->addHookBefore('ProcessPageEdit::execute', $this, 'multilanguageStatusCheck');

	}


    /**
     * Checks if page should show multi-language inputs
     *
     * @param HookEvent $event
     */
    public function multilanguageStatusCheck(HookEvent $event) {

        $p = $event->object->getPage();

        // if actual noLang setting for this page's template is set to disabled (1) then exit now so we don't potentially enable
        if($p->template->noLang === 1) return;

        $parentsToMatch = array();
        foreach($this->data['multilanguageStatus'] as $pid => $settings) {
            if($settings['onlyThisPage'] != 1 || $p->id === $pid) $parentsToMatch[] = $pid;
        }
        $pageMatchSelector = "id=".implode("|", $parentsToMatch);

        // parent() doesn't include current page
        $this->closestSpecifiedParent = $p->parent($pageMatchSelector);
        // closest() includes current page
        $this->closestMatch = $p->closest($pageMatchSelector);

        // if there's a match, set the noLang value for the page's template appropriately
        if(isset($this->data['multilanguageStatus'][$this->closestMatch->id])) {
            if($this->data['multilanguageStatus'][$this->closestMatch->id]['status'] == 'disabled') {
                $p->template->noLang = '1';
                // we want repeater fields single language as well
                foreach($p->fields as $f) {
                    if($f->type instanceof FieldtypeRepeater === false) continue;
                    $this->wire('templates')->get(FieldtypeRepeater::templateNamePrefix . $f->name)->noLang = '1';
                }
            }
            else {
                $p->template->noLang = '0';
            }
        }
        // if no match, then use default from config settings
        else {
            $p->template->noLang = $this->data['multilanguageDefaultStatus'] == 'disabled' ? '1' : '0';
        }
    }


    public function buildMultilanguageStatusForm(HookEvent $event){

        $p = $event->object->getPage();
        $inputfields = $event->return;

        $fieldset = $this->wire('modules')->get("InputfieldFieldset");
        $fieldset->attr('id', 'multilanguage_fieldset');
        $fieldset->label = __("Multilanguage Restrictions");

        if($p->template->noLang !== 1) {
            $f = $this->wire('modules')->get("InputfieldRadios");
            $f->attr('name', 'multilanguageStatus');
            $f->label = __('Multi-Language Status');
            $f->description = __("Should multi-language inputs be enabled or disabled for this page/branch?");
            if($p->id === 1 || !$this->closestSpecifiedParent || !isset($this->data['multilanguageStatus'][$this->closestSpecifiedParent->id])) {
                $inherited = ' "'.$this->data['multilanguageDefaultStatus'].'" status from the module config settings.';
                $f->addOption('inherit', 'Inherit' . $inherited);
            }
            else {
                if($p !== $this->closestSpecifiedParent) {
                    $inherited = ' "'.$this->data['multilanguageStatus'][$this->closestSpecifiedParent->id]['status'].'" status from '.$this->closestSpecifiedParent->path;
                }
                $f->addOption('inherit', 'Inherit' . $inherited);
            }
            $f->addOption('enabled', 'Enabled');
            $f->addOption('disabled', 'Disabled');
            $f->value = isset($this->data['multilanguageStatus']) && array_key_exists($p->id, $this->data['multilanguageStatus']) ? $this->data['multilanguageStatus'][$p->id]['status'] : 'inherit';
            $fieldset->add($f);

            $f = $this->wire('modules')->get('InputfieldCheckbox');
            $f->attr('name', 'onlyThisPage');
            $f->label = 'Only this page';
            $f->description = __("Check to prevent enabled/disabled setting above from being inherited by all children/grandchildren of this page.");
            $f->notes = __("If not checked, children/grandchlidren of this page will inherit " . $inherited);
            $f->showIf = "multilanguageStatus!=inherit";
            $f->attr('checked', isset($this->data['multilanguageStatus'][$p->id]) && $this->data['multilanguageStatus'][$p->id]['onlyThisPage'] ? 'checked' : '' );
            $fieldset->append($f);
        }
        else {
            $f = $this->wire('modules')->get("InputfieldMarkup");
            $f->attr('name', 'templateDisabled');
            $f->label = __('Language Branch Restrictions');
            $f->attr('value', "The template for this page has disabled multi-language support, so we don't want to override here.");
            $fieldset->append($f);
        }

        $inputfields->append($fieldset);

    }


    public function processMultilanguageStatusForm(HookEvent $event){

        // ProcessPageEdit's processInput function may go recursive, so we want to skip
        // the instances where it does that by checking the second argument named "level"
        $level = $event->arguments(1);
        if($level > 0) return;

        $p = $event->object->getPage();
        if($p->matches("has_parent={$this->wire('config')->adminRootPageID}|{$this->wire('config')->trashPageID}")) return;

        $options = array(
            "pid" => $p->id,
            "multilanguageStatus" => $this->wire('input')->post->multilanguageStatus,
            "onlyThisPage" => $this->wire('input')->post->onlyThisPage
        );

        $options = array_merge($this->multilanguageRestrictOptions, $options);

        // create array of options and modify keys so it can be compared to existing settings for this page
        // if they don't match existing settings, or no settings exist, then save settings
        $optionsToCompare = $options;
        unset($optionsToCompare['pid']);
        $optionsToCompare['status'] = $options['multilanguageStatus'];
        unset($optionsToCompare['multilanguageStatus']);

        if(
            (isset($this->data['multilanguageStatus'][$p->id]) && $this->data['multilanguageStatus'][$p->id] != $optionsToCompare) ||
            (!isset($this->data['multilanguageStatus'][$p->id]) && $optionsToCompare['status'] != 'inherit')
        ) {
            $this->saveSettings($options);
        }

    }


    public function saveSettings($options) {
        $pid = $options['pid'];
        unset($this->data['multilanguageStatus'][$pid]); // remove existing record for this page - need a clear slate for adding new settings or if it was just disabled

        // if inherit then we don't want to save - just a waste of space in the module settings
        if($this->wire('input')->post->multilanguageStatus !== 'inherit') {
            $this->data['multilanguageStatus'][$pid]['status'] = $options['multilanguageStatus'];
            $this->data['multilanguageStatus'][$pid]['onlyThisPage'] = $options['onlyThisPage'];
        }

        // save to config data with the rest of the settings
        $this->wire('modules')->saveModuleConfigData($this, $this->data);
    }

    /**
     * Return an InputfieldsWrapper of Inputfields used to configure the class
     *
     * @param array $data Array of config values indexed by field name
     * @return InputfieldsWrapper
     *
     */
    public function getModuleConfigInputfields(array $data) {

        $data = array_merge(self::getDefaultData(), $data);

        $wrapper = new InputfieldWrapper();

        $f = $this->wire('modules')->get("InputfieldMarkup");
        $f->attr('name', 'table');
        $f->label = __('Language Branch Restrictions');
        $value = '';
        if(empty($data['multilanguageStatus'])) {
            $value .= "<h3 style='color:#990000'>Currently no pages have language restrictions.<br />To set language restrictions, you need to configure on each page's Settings tab.</h3>";
        }
        else{
            $value .= "<h3 style='color:#009900'>Currently there " . (count($data['multilanguageStatus']) >1 ? " are " : " is ") . count($data['multilanguageStatus'])." page" . (count($data['multilanguageStatus']) >1 ? "s" : "") . " with a specified language restriction status.</h3>";
        }
        $f->description = __("Go to the settings tab of a page and adjust the \"Multi-language Restrictions\" settings.");
        $f->notes = __("Children inherit restrictions from their parents unless 'This Page Only' is set to True.");

        if(isset($data['multilanguageStatus'])) {
            $table = $this->wire('modules')->get("MarkupAdminDataTable");
            $table->setEncodeEntities(false);
            $table->setSortable(false);
            $table->setClass('languagerestrictor');
            $table->headerRow(array(
                __('Title'),
                __('Path'),
                __('Status'),
                __('This Page Only'),
                __('Edit')
            ));
            foreach($data['multilanguageStatus'] as $pid => $settings) {
                $row = array(
                    $this->wire('pages')->get($pid)->title,
                    $this->wire('pages')->get($pid)->path,
                    ucfirst($settings['status']),
                    ($settings['onlyThisPage'] ? 'True' : 'False'),
                    '<a href="'.$this->wire('config')->urls->admin.'page/edit/?id='.$pid.'#ProcessPageEditSettings">edit</a>'
                );
                $table->row($row);
            }
            $value .= $table->render();
        }
        $f->attr('value', $value);
        $wrapper->add($f);

        $f = $this->wire('modules')->get("InputfieldRadios");
        $f->attr('name', 'multilanguageDefaultStatus');
        $f->label = __('Multi-Language Default Status');
        $f->description = __("Should multi-language inputs be enabled or disabled by default for the entire page tree?");
        $f->addOption('enabled', 'Enabled');
        $f->addOption('disabled', 'Disabled');
        $f->value = isset($data['multilanguageDefaultStatus']) ? $data['multilanguageDefaultStatus'] : 'enabled';
        $wrapper->add($f);

        return $wrapper;
    }

}
