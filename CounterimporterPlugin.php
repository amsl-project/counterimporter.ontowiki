<?php
/**
 * This file is part of the {@link http://ontowiki.net OntoWiki} project.
 *
 * @copyright Copyright (c) 2013, {@link http://amsl.technologyW}
 * @license   http://opensource.org/licenses/gpl-license.php GNU General Public License (GPL)
 */

/**
 * The main class for the basicimporter plugin.
 *
 * @category   OntoWiki
 * @package    Extensions_Counter
 * @author     Norman Radtke <radtke@ub.uni-leipzig.de>
 * @author     Sebastian Tramp <tramp@informatik.uni-leipzig.de>
 */
class CounterimporterPlugin extends OntoWiki_Plugin
{
    /*
     * our event method
     */
    public function onProvideImportActions($event)
    {
        $this->provideImportActions($event);
    }

    /*
     * here we add new import actions
     */
    private function provideImportActions($event)
    {
        $translate = OntoWiki::getInstance()->translate;
        $myImportActions = array(
            'counter--titlelist' => array(
                'controller' => 'counterimporter',
                'action' => 'sushixml',
                'label' => $translate->translate('Import a counter xml file'),
                'description' => 'Tries to generate triples out of a counter xml file'
            )
        );

        // sad but true, some php installation do not allow this
        if (!ini_get('allow_url_fopen')) {
            unset($myImportActions['basicimporter-rdfwebimport']);
        }

        $event->importActions = array_merge($event->importActions, $myImportActions);
        return $event;
    }
}
