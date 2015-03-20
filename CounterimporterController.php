<?php
/**
 * This file is part of the {@link http://ontowiki.net OntoWiki} project.
 *
 * @copyright Copyright (c) 2014, {@link http://amsl.technology}
 * @license   http://opensource.org/licenses/gpl-license.php GNU General Public License (GPL)
 */

/**
 * Controller for OntoWiki Basicimporter Extension
 *
 * @category OntoWiki
 * @package  Extensions_Issnimporter
 * @author   Norman Radtke <radtke@ub.uni-leipzig.de>
 */
class CounterimporterController extends OntoWiki_Controller_Component
{
    private $_model              = null;
    private $_post               = null;
    private $_organizations      = null;
    private $_sushiSettings      = null;
    private $_autocompletionData = null;
    private $_rprtRes            = null;
    private $_reportUri          = null;

    // some namespaces
    const NS_AMSL   = 'http://vocab.ub.uni-leipzig.de/amsl/';
    const NS_BASE   = 'http://amsl.technology/counter/resource/';
    const NS_COUNTR = 'http://vocab.ub.uni-leipzig.de/counter/';
    const NS_DC     = 'http://purl.org/dc/elements/1.1/';
    const NS_FOAF   = 'http://xmlns.com/foaf/0.1/';
    const NS_SKOS   = 'http://www.w3.org/2004/02/skos/core#';
    const NS_TERMS  = 'http://vocab.ub.uni-leipzig.de/amslTerms/';
    const NS_VCARD  = 'http://www.w3.org/2006/vcard/ns#';
    const NS_XSD    = 'http://www.w3.org/2001/XMLSchema#';

    /**
     * init() Method to init() normal and add tabbed Navigation
     */
    public function init()
    {
        parent::init();

        $action = $this->_request->getActionName();

        $this->view->placeholder('main.window.title')->set('Import Data');
        $this->view->formActionUrl    = $this->_config->urlBase . 'counterimporter/' . $action;
        $this->view->formEncoding     = 'multipart/form-data';
        $this->view->formClass        = 'simple-input input-justify-left';
        $this->view->formMethod       = 'post';
        $this->view->formName         = 'importdata';
        $this->view->supportedFormats = $this->_erfurt->getStore()->getSupportedImportFormats();

        $this->view->headScript()->appendFile($this->_config->urlBase .
            'extensions/counterimporter/templates/counterimporter/js/typeahead.bundle.js');
        $this->view->headScript()->appendFile($this->_config->urlBase .
            'extensions/counterimporter/templates/counterimporter/js/search.js');
        $this->view->headLink()->appendStylesheet($this->_config->urlBase .
            'extensions/counterimporter/templates/counterimporter/css/counter.css');

        $this->_owApp = OntoWiki::getInstance();
        $this->_model = $this->_owApp->selectedModel;

        // add a standard toolbar
        $toolbar = $this->_owApp->toolbar;
        $toolbar->appendButton(
            OntoWiki_Toolbar::SUBMIT,
            array('name' => 'Import Data', 'id' => 'importdata')
        )->appendButton(
            OntoWiki_Toolbar::RESET,
            array('name' => 'Cancel', 'id' => 'importdata')
        );
        $this->view->placeholder('main.window.toolbar')->set($toolbar);
        OntoWiki::getInstance()->getNavigation()->disableNavigation();

        if ($this->_request->isPost()) {
            $this->_post = $this->_request->getPost();
        }
        $this->_setOrganizations();
    }

    /**
     * This action will return a json_encoded array
     */
    public function getorganizationsAction()
    {

        // tells the OntoWiki to not apply the template to this action
        $this->_helper->viewRenderer->setNoRender();
        $this->_helper->layout->disableLayout();

        if ($this->_autocompletionData === null) {
            $this->_setOrganizations();
        }

        $this->_response->setBody($this->_autocompletionData);
    }

    /**
     * The main method. Parses a given counter xml file and writes triples to the store
     */
    public function counterxmlAction()
    {
        $this->view->placeholder('main.window.title')->set('Upload a counter xml file');

        if ($this->_request->isPost()) {
            $post = $this->_request->getPost();
            $upload = new Zend_File_Transfer();
            $filesArray = $upload->getFileInfo();

            $message = '';
            switch (true) {
                case empty($filesArray):
                    $message = 'upload went wrong. check post_max_size in your php.ini.';
                    break;
                case ($filesArray['source']['error'] == UPLOAD_ERR_INI_SIZE):
                    $message = 'The uploaded files\'s size exceeds the upload_max_filesize directive in php.ini.';
                    break;
                case ($filesArray['source']['error'] == UPLOAD_ERR_PARTIAL):
                    $message = 'The file was only partially uploaded.';
                    break;
                case ($filesArray['source']['error'] >= UPLOAD_ERR_NO_FILE):
                    $message = 'Please select a file to upload';
                    break;
            }

            if ($message != '') {
                $this->_owApp->appendErrorMessage($message);
                return;
            }

            $file = $filesArray['source']['tmp_name'];
            // setting permissions to read the tempfile for everybody
            // (e.g. if db and webserver owned by different users)
            chmod($file, 0644);
        } else {
            return;
        }


        // READING XML file
        $xmlstr = file_get_contents($file);
        $xmlstr = str_replace('xmlns=', 'ns=', $xmlstr);
        $xml = new SimpleXMLElement($xmlstr);
        $counterUrl = 'http://www.niso.org/schemas/counter';
        $ns = $xml->getDocNamespaces(true);
        if (count($ns) != 0) {
            if (isset($ns['xmlns']) && $ns['xmlns'] === $counterUrl) {
                $implicit = true;
            }

            if (in_array($counterUrl, $ns)) {
                $flippedNs = array_flip($ns);
                $counterNS = $flippedNs[$counterUrl];
                $xml->registerXPathNamespace($counterNS, 'http://www.niso.org/schemas/counter');
            }
        }

        $reportsFound = false;

        // Try to find a report node in counter namespace
        $reports = $xml->children($counterUrl);
        if (count($reports) !== 0 ) {
            if (isset($reports->Report)) {
                $reportsFound = true;
                foreach ($reports->Report as $report) {
                    $attributes = $report->attributes();
                    $this->_writeReport($report, $attributes);
                }
            }
        }

        // If no success try to find reports without namespace
        if ($reportsFound === false) {
            $reports = $xml->xpath('//Report');
            if (!(count($reports) === 0 || $reports === null)) {
                foreach ($reports as $report) {
                    if (isset($report->Vendor)) {
                        $reportsFound = true;
                        // we are a Report node
                        if ($report->attributes() !== null) {
                            $attributes = $report->attributes();
                            $this->_writeReport($report, $attributes);
                        }
                    }
                }
            }
        }

        if ($reportsFound === false) {
            $this->_owApp->appendSuccessMessage('Nothing imported. No report data found');
            return;
        }

        // import statements

        // starting action
        $modelIri = (string)$this->_model;
        $versioning = $this->_erfurt->getVersioning();
        // action spec for versioninghnology/counter
        $actionSpec = array();
        $actionSpec['type'] = 11;
        $actionSpec['modeluri'] = $modelIri;
        $actionSpec['resourceuri'] = $modelIri;

        try {
            $versioning->startAction($actionSpec);
            // TODO write one array if tested from librarians
            $this->_model->addMultipleStatements($this->_rprtRes);

            $versioning->endAction();

            // Trigger Reindex
            $indexEvent = new Erfurt_Event('onFullreindexAction');
            $indexEvent->trigger();
        } catch (Exception $e) {
            $message = $e->getMessage();
            $this->_owApp->appendErrorMessage('Could not import counter xml: ' . $message);
            return;
        }

        $this->_owApp->appendSuccessMessage('Data successfully imported.');
    }

    private function _writeReport($report, $attributes)
    {
        // regular expressions
        $regISBN = '/\b(?:ISBN(?:: ?| ))?((?:97[89])?\d{9}[\dx])\b/i';
        $regISSN = '/\d{4}\-\d{3}[\dxX]/';

        // create report uri
        $this->_reportUri = $this::NS_BASE . 'report/' . md5(rand());

        $this->_rprtRes[$this->_reportUri][EF_RDF_TYPE][] = array(
            'type' => 'uri',
            'value' => $this::NS_COUNTR . 'Report'
        );

        // Check if date is a valid date (string methods used)
        if (strlen($attributes->Created > 9)) {
            $substring = substr($attributes->Created, 0, 10);
            $year = substr($substring, 0, 4);
            $hyphen1 = substr($substring, 4, 1);
            $month = substr($substring, 5, 2);
            $hyphen2 = substr($substring, 7, 1);
            $day = substr($substring, 8, 2);
            $test = $hyphen1 . $hyphen2;
            $dateIsNumeric = false;
            if (is_numeric($year) && is_numeric($month) && is_numeric($day)) {
                $dateIsNumeric = true;
            }
            if ($dateIsNumeric === true) {
                if (checkdate($month, $day, $year) === true && $test === '--') {
                    $date = $year . '-' . $month . '-' . $day;
                    $this->_rprtRes[$this->_reportUri][$this::NS_COUNTR . 'wasCreatedOn'][] =
                        array(
                            'type' => 'literal',
                            'value' => $date,
                            'datatype' => $this::NS_XSD . 'date'
                        );
                }
            } else {
                $this->_rprtRes[$this->_reportUri][$this::NS_COUNTR . 'wasCreatedOn'][] = array(
                    'type' => 'literal',
                    'value' => date('c'),
                    'datatype' => $this::NS_XSD . 'dateTime'
                );
            }
        }

        $value = (string)$attributes->ID;                                           // Report Id
        if (!(empty($value))) {
            $this->_rprtRes[$this->_reportUri][$this::NS_COUNTR . 'hasReportID'][] = array(
                'type' => 'literal',
                'value' => $value
            );
        }

        $value = (string)$attributes->Version;                                 // Report Version
        if (!(empty($value))) {
            $this->_rprtRes[$this->_reportUri][$this::NS_COUNTR . 'hasReportVersion'][] = array(
                'type' => 'literal',
                'value' => $value
            );
        }

        $value = (string)$attributes->Name;                                      // Report Title
        if (!(empty($value))) {
            $this->_rprtRes[$this->_reportUri][$this::NS_COUNTR . 'hasReportTitle'][] = array(
                'type' => 'literal',
                'value' => $value
            );
            $this->_rprtRes[$this->_reportUri][EF_RDFS_LABEL][] = array(
                'type' => 'literal',
                'value' => 'Report: ' . $value
            );
        }

        // Vendor data
        $vendor = $report->Vendor;
        $this->_writeOrganizationData($vendor, 'Vendor');

        // Custumor data
        $customer = $report->Customer;
        $this->_writeOrganizationData($customer, 'Customer');

        // Report Items
        foreach ($customer->ReportItems as $reportItem) {
            $itemName = (string)$reportItem->ItemName;
            if (!(empty($itemName))) {
                $itemUri = $this::NS_BASE . 'reportitem/' . urlencode($itemName);
            } else {
                $itemUri = $this::NS_BASE . 'reportitem/' . md5(rand());
            }

            $this->_rprtRes[$itemUri][EF_RDF_TYPE][] = array(
                'type' => 'uri',
                'value' => $this::NS_COUNTR . 'ReportItem'
            );

            if (!(empty($itemName))) {
                $this->_rprtRes[$itemUri][EF_RDFS_LABEL][] = array(
                    'type' => 'literal',
                    'value' => $itemName
                );
            }

            $this->_rprtRes[$itemUri][$this::NS_COUNTR . 'isContainedIn'][] = array(
                'type' => 'uri',
                'value' => $this->_reportUri
            );

            $platform = (string)$reportItem->ItemPlatform;

            if (!(empty($platform))) {
                $platformUri = $this::NS_BASE . 'platform/' . urlencode($platform);
                $this->_rprtRes[$platformUri][EF_RDF_TYPE][] = array(
                    'type' => 'uri',
                    'value' => $this::NS_COUNTR . 'Platform'
                );

                $this->_rprtRes[$platformUri][$this::NS_SKOS . 'altLabel'][] = array(
                    'type' => 'literal',
                    'value' => $platform
                );

                $this->_rprtRes[$itemUri][$this::NS_COUNTR . 'isAccessibleVia'][] = array(
                    'type' => 'uri',
                    'value' => $platformUri
                );
            }

            $itemPublisher = (string)$reportItem->ItemPublisher;
            if (!(empty($itemPublisher))) {
                // TODO Match
                $publisherUri = $this::NS_BASE . 'publisher/' . urlencode($itemPublisher);
                $this->_rprtRes[$publisherUri][EF_RDF_TYPE][] = array(
                    'type' => 'uri',
                    'value' => $this::NS_COUNTR . 'Publisher'
                );

                $this->_rprtRes[$itemUri][$this::NS_DC . 'publisher'][] = array(
                    'type' => 'uri',
                    'value' => $publisherUri
                );
            }

            $itemDataType = (string)$reportItem->ItemDataType;
            if (!(empty($itemPublisher))) {
                $this->_rprtRes[$itemUri][$this::NS_COUNTR . 'hasItemDataType'][] = array(
                    'type' => 'uri',
                    'value' => $this::NS_COUNTR . $itemDataType
                );
            }

            foreach ($reportItem->ItemIdentifier as $itemIdentifier) {
                $itemIdValue = (string)$itemIdentifier->Value;
                $itemIdType = (string)$itemIdentifier->Type;
                if (!(empty($itemIdValue))) {
                    if (!(empty($itemIdType))) {
                        $pred = '';
                        switch (strtolower($itemIdType)) {
                            case 'doi':
                                if (substr($itemIdValue, 0, 3) === '10.') {
                                    $uri = 'http://doi.org/' . $itemIdValue;
                                    $pred = $this::NS_AMSL . 'doi';
                                } else
                                    break;
                            case 'online_issn':
                                if (preg_match($regISSN, $itemIdValue)) {
                                    $uri = 'urn:ISSN:' . $itemIdValue;
                                    $pred = $this::NS_AMSL . 'eissn';
                                }
                                break;
                            case 'print_issn':
                                if (preg_match($regISSN, $itemIdValue)) {
                                    $uri = 'urn:ISSN:' . $itemIdValue;
                                    $pred = $this::NS_AMSL . 'pissn';
                                }
                                break;
                            case 'online_isbn':
                                if (preg_match($regISBN, $itemIdValue)) {
                                    $uri = 'urn:ISBN:' . $itemIdValue;
                                    $pred = $this::NS_AMSL . 'eisbn';
                                }
                                break;
                            case 'print_isbn':
                                if (preg_match($regISBN, $itemIdValue)) {
                                    $uri = 'urn:ISBN:' . $itemIdValue;
                                    $pred = $this::NS_AMSL . 'pisbn';
                                }
                                break;
                            case 'proprietaryID':
                                if (preg_match($regISBN, $itemIdValue)) {
                                    $uri = $this::NS_BASE . 'ProprietaryID/' . $itemIdValue;
                                    $pred = $this::NS_AMSL . 'proprietaryId';
                                }
                                break;
                        }
                        if ($pred !== '') {
                            $this->_rprtRes[$itemUri][$pred][] = array(
                                'type' => 'uri',
                                'value' => $uri
                            );
                        }
                    }
                }
            }

            $pubYr = (string)$reportItem->ItemPerformance->PubYr;
            $pubYrFrom = (string)$reportItem->ItemPerformance->PubYrFrom;
            $pubYrTo = (string)$reportItem->ItemPerformance->PubYrTo;
            if (!(empty($pubYrFrom)) && !(empty($pubYrTo))) {
                $pubYrFrom = $pubYrFrom . '-01-01';
                $pubYrTo = $pubYrTo . '-12-31';
                $pubUri = $this::NS_BASE . 'daterange/' . urlencode($pubYrFrom . '-' . $pubYrTo);
            } else {
                if (!(empty($pubYr))) {
                    $pubYrFrom = $pubYr . '-01-01';
                    $pubYrTo = $pubYr . '-12-31';
                    $pubUri = $this::NS_BASE . 'daterange/' . urlencode($pubYrFrom . '-' . $pubYrTo);
                } else {
                    $pubUri = '';
                }
            }
            if ($pubUri !== '') {
                $this->_rprtRes[$pubUri][EF_RDF_TYPE][] = array(
                    'type' => 'uri',
                    'value' => $this::NS_COUNTR . 'DateRange'
                );
                $this->_rprtRes[$pubUri][EF_RDFS_LABEL][] = array(
                    'type' => 'literal',
                    'value' => 'DateRange: ' . $pubYrFrom . ' - ' . $pubYrTo
                );
                $this->_rprtRes[$pubUri][$this::NS_COUNTR . 'hasStartDay'][] = array(
                    'type' => 'literal',
                    'value' => $pubYrFrom
                );
                $this->_rprtRes[$pubUri][$this::NS_COUNTR . 'hasEndDay'][] = array(
                    'type' => 'literal',
                    'value' => $pubYrTo
                );
            }

            // save date ranges to link to them from instances during
            // another foreach loop located at same xml hierarchy level
            foreach ($reportItem->ItemPerformance as $itemPerformance) {
                $perfCategory = (string)$itemPerformance->Category;
                $start = (string)$itemPerformance->Period->Begin;
                $end = (string)$itemPerformance->Period->End;
                $dateRangeUri = $this::NS_BASE . 'daterange/' . urlencode($start . '-' . $end);
                $this->_rprtRes[$this->_reportUri][$this::NS_COUNTR . 'hasPerformance'][] = array(
                    'type' => 'uri',
                    'value' => $dateRangeUri
                );
                $this->_rprtRes[$dateRangeUri][EF_RDF_TYPE][] = array(
                    'type' => 'uri',
                    'value' => $this::NS_COUNTR . 'DateRange'
                );
                $this->_rprtRes[$dateRangeUri][EF_RDFS_LABEL][] = array(
                    'type' => 'literal',
                    'value' => 'DateRange: ' . $start . ' - ' . $end
                );
                $this->_rprtRes[$dateRangeUri][$this::NS_COUNTR . 'hasStartDay'][] = array(
                    'type' => 'literal',
                    'value' => $start
                );
                $this->_rprtRes[$dateRangeUri][$this::NS_COUNTR . 'hasEndDay'][] = array(
                    'type' => 'literal',
                    'value' => $end
                );

                foreach ($itemPerformance->Instance as $instance) {
                    $instanceUri = $this::NS_BASE . 'countinginstance/' . md5(rand());
                    $metricType = (string)$instance->MetricType;
                    $count = (string)$instance->Count;

                    // link from report item resource
                    $this->_rprtRes[$itemUri][$this::NS_COUNTR . 'hasPerformance'][] = array(
                        'type' => 'uri',
                        'value' => $instanceUri
                    );

                    // write counting instance
                    $this->_rprtRes[$instanceUri][EF_RDF_TYPE][] = array(
                        'type' => 'uri',
                        'value' => $this::NS_COUNTR . 'CountingInstance'
                    );

                    if ($pubUri !== '') {
                        $this->_rprtRes[$instanceUri][$this::NS_COUNTR . 'considersPubYear'][] = array(
                            'type' => 'uri',
                            'value' => $pubUri
                        );
                    }

                    $this->_rprtRes[$instanceUri][$this::NS_COUNTR . 'measureForPeriod'][] = array(
                        'type' => 'uri',
                        'value' => $dateRangeUri
                    );

                    if (!(empty($perfCategory))) {
                        $this->_rprtRes[$instanceUri][$this::NS_COUNTR . 'hasCategory'][] = array(
                            'type' => 'uri',
                            'value' => $this::NS_COUNTR . 'category/' . $perfCategory
                        );
                    }

                    $this->_rprtRes[$instanceUri][$this::NS_COUNTR . 'hasMetricType'][] = array(
                        'type' => 'uri',
                        'value' => $this::NS_BASE . 'metrictype/' . $metricType
                    );

                    $this->_rprtRes[$instanceUri][$this::NS_COUNTR . 'hasCount'][] = array(
                        'type' => 'literal',
                        'value' => $count,
                        "datatype" => EF_XSD_INTEGER
                    );
                }
            }
        }
    }

    /**
     * This method searches for organizations and their labels
     * It creates 2 arrays. One can be used for levenshtein matching
     * the other for suggestion engine via javascript
     */
    private function _setOrganizations() {
        if ($this->_model === null) {
            return;
        }

        $query = 'SELECT DISTINCT *  WHERE {' . PHP_EOL ;
        $query.= '  ?org a <' . $this::NS_FOAF . 'Organization> .' . PHP_EOL;
        $query.= '  OPTIONAL {?org <' . $this::NS_VCARD . 'organization-name> ?name .}' . PHP_EOL;
        $query.= '  OPTIONAL {?org <' . EF_RDFS_LABEL . '> ?label .}' . PHP_EOL;
        $query.= '  OPTIONAL {?org <' . $this::NS_COUNTR . 'hasOrganizationName> ?cntrName .}' . PHP_EOL;
        $query.= '}' . PHP_EOL;

        $result = $this->_model->sparqlQuery($query);

        $organizations = array();
        $temp = array();
        if (count($result) > 0) {
            foreach ($result as $key => $organization) {
                // Write data used for matching
                $organizations[$organization['org']]['org'] = $organization['org'];

                // Write data used for js suggestions
                if (!(empty($organization['cntrName']))) {
                    $value = $organization['cntrName'];
                    $organizations[$organization['org']]['cntrName'] = $organization['cntrName'];
                } else {
                    if (!(isset($organizations[$organization['org']]['cntrName']))) {
                        $organizations[$organization['org']]['cntrName'] = '';
                    }
                }
                if (!(empty($organization['label']))) {
                    $value = $organization['label'];
                    $organizations[$organization['org']]['label'] = $organization['label'];
                } else {
                    if (!(isset($organizations[$organization['org']]['label']))) {
                        $organizations[$organization['org']]['label'] = '';
                    }
                }
                if (!(empty($organization['name']))) {
                    $value = $organization['name'];
                    $organizations[$organization['org']]['name'] = $organization['name'];
                } else {
                    if (!(isset($organizations[$organization['org']]['name']))) {
                        $organizations[$organization['org']]['name'] = '';
                    }
                }

                $temp[] = array(
                    'org' => $organization['org'],
                    'label' => $value
                );
            }
            $this->_organizations = $organizations;
            // Delete duplicates -> returns an associative array
            $temp = $this->_super_unique($temp);
            // Create a new non associative array
            $json = array();
            foreach ($temp as $value) {
                $json[] = $value;
            }
            $this->_autocompletionData = json_encode($json);
        } else {
            $this->_autocompletionData = json_encode($temp);
            $this->_organizations = null;
        }
    }


    /**
     * This method uses a SPARQL query to find organization names that will be matched
     * @param $orgName string to be matched
     * @return An array containing the value of the best match the used property, the best matched
     * literal and the URI of the corresponding organization
     * organization URI
     */
    private function _matchOrganization($orgName) {

        if ($this->_organizations !== null) {
            $lev4All[$this::NS_VCARD . 'organization-name'] = 0;
            $lev4All[EF_RDFS_LABEL] = 0;
            $lev4All[$this::NS_COUNTR . 'hasOrganizationName'] = 0;
            $bestMatch['quality'] = 0;

            foreach ($this->_organizations as $organization) {
                if (!(empty($organization['name']))) {
                    $lev4All[$this::NS_VCARD . 'organization-name'] = $this->_relLevenshtein(
                        $organization['name'], $orgName);
                    if ($lev4All[$this::NS_VCARD . 'organization-name'] >= $bestMatch['quality']) {
                        $bestMatch['quality'] = $lev4All[$this::NS_VCARD . 'organization-name'];
                        $bestMatch['property'] = $this::NS_VCARD . 'organization-name';
                        $bestMatch['literal'] = $organization['name'];
                        $bestMatch['orgUri'] = $organization['org'];
                    }
                }
                if (!empty($organization['label'])) {
                    $lev4All[EF_RDFS_LABEL] = $this->_relLevenshtein(
                        $organization['label'], $orgName);
                    if ($lev4All[EF_RDFS_LABEL] >= $bestMatch['quality']) {
                        $bestMatch['quality'] = $lev4All[EF_RDFS_LABEL];
                        $bestMatch['property'] = EF_RDFS_LABEL;
                        $bestMatch['literal'] = $organization['label'];
                        $bestMatch['orgUri'] = $organization['org'];
                    }
                }
                if (!empty($organization['cntrName'])) {
                    $lev4All[$this::NS_COUNTR . 'hasOrganizationName'] = $this->_relLevenshtein(
                        $organization['cntrName'], $orgName);
                    if ($lev4All[$this::NS_COUNTR . 'hasOrganizationName'] >= $bestMatch['quality']) {
                        $bestMatch['quality'] = $lev4All[$this::NS_COUNTR . 'hasOrganizationName'];
                        $bestMatch['property'] = $this::NS_COUNTR . 'hasOrganizationName';
                        $bestMatch['literal'] = $organization['cntrName'];
                        $bestMatch['orgUri'] = $organization['org'];
                    }
                }
            }
            return $bestMatch;
        } else {
            return false;
        }
    }

    /**
     * This method computes the levenshtein distance according to the string lenghts
     @param $string1 The first string
     @param $string 2 The second string
     @return $value A value x | 0 >= x >= 1
     */
    private function _relLevenshtein ($string1, $string2) {
        $levDis = levenshtein(strtolower($string1), strtolower($string2));
        $maxLen = max(strlen($string1), strlen($string2));
        $value = ($maxLen - $levDis) / $maxLen;
        return $value;
    }

    private function _super_unique($array)
    {
        $result = array_map("unserialize", array_unique(array_map("serialize", $array)));

        foreach ($result as $key => $value)
        {
            if ( is_array($value) )
            {
                $result[$key] = $this->_super_unique($value);
            }
        }
        return $result;
    }

    /**
     * @param $organization
     * @param $type
     * @return mixed
     */
    private function _writeOrganizationData ($organization, $type) {
        $orgRes = array();
        if ($type === 'Vendor') {
            $predicate = 'creates';
        } elseif ($type === 'Customer') {
            $predicate = 'receives';
        }

        $contact = $organization->Contact;

        $organizationName    = (string)$organization->Name;
        $organizationId      = (string)$organization->ID;
        $organizationWebSite = (string)$organization->WebSiteUrl;
        $organizationLogoUrl = (string)$organization->LogoUrl;
        if ($contact !== null) {
            $contactType = (string)$contact->Contact;
            $contactMail = (string)$contact->{'E-mail'};
        }

        // Find a customer URI
        if (!(empty($organizationName))) {
            $organizationUri = $this::NS_BASE . 'organization/' . urlencode($organizationName);
            $bestMatch = $this->_matchOrganization($organizationName);
            $this->_writeOrganizationMatches($bestMatch, $type);
        } else {
            if (!(empty($organizationId))) {
                $organizationUri = $this::NS_BASE . 'organization/' . urlencode($organizationId);
            } elseif (!(empty($organizationMail) && empty($organizationWebSite) &&
                empty($organizationLogoUrl))
            ) {
                $organizationUri = $this::NS_BASE . 'organization/' . md5(rand());
            } else {
                $organizationUri = '';
            }
        }

        if (!(empty($organizationUri))) {
            $this->_rprtRes[$organizationUri][EF_RDF_TYPE][] = array(
                'type' => 'uri',
                'value' => $this::NS_FOAF . 'Organization'
            );

            $this->_rprtRes[$organizationUri][EF_RDF_TYPE][] = array(
                'type' => 'uri',
                'value' => $this::NS_COUNTR . 'Customer'
            );

            $this->_rprtRes[$organizationUri][$this::NS_COUNTR . $predicate][] = array(
                'type' => 'uri',
                'value' => $this->_reportUri
            );

            $this->_rprtRes[$organizationUri][$this::NS_SKOS . 'altLabel'][] = array(
                'type' => 'literal',
                'value' => $organizationName . ' [COUNTER]'
            );

            $this->_rprtRes[$organizationUri][$this::NS_VCARD . 'organization-name'][] = array(
                'type' => 'literal',
                'value' => $organizationName . ' [COUNTER]'
            );

            if (!(empty($organizationWebSite))) {
                $this->_rprtRes[$organizationUri][$this::NS_VCARD . 'hasURL'][] = array(
                    'type' => 'literal',
                    'value' => $organizationWebSite
                );
            };

            if (!(empty($organizationID))) {
                $this->_rprtRes[$organizationUri][$this::NS_COUNTR . 'hasOrganizationID'][] = array(
                    'type' => 'literal',
                    'value' => $organizationID
                );
            };

            if (!(empty($contactMail))) {
                if (!(substr($contactMail, 0, 7) === 'mailto:')) {
                    $contactMail = 'mailto:' . $contactMail;
                }
                // TODO Evtl. noch auf URI (Errfurt) überprüfen, allerdings weiß ich nicht,
                // ob mailto erkannt wird
                $this->_rprtRes[$organizationUri][$this::NS_VCARD . 'hasEmail'][] = array(
                    'type' => 'uri',
                    'value' => $contactMail
                );

                if (!(empty($contactType))) {
                    $this->_rprtRes[$contactMail][EF_RDFS_COMMENT][] = array(
                        'type' => 'literal',
                        'value' => $contactType . ' [COUNTER]'
                    );
                } else {
                    $this->_rprtRes[$contactMail][EF_RDFS_COMMENT][] = array(
                        'type' => 'literal',
                        'value' => 'No further information given [COUNTER]'
                    );
                }
            };

            if (!(empty($organizationLogoUrl))) {
                $this->_rprtRes[$organizationUri][$this::NS_COUNTR . 'hasLogoUrl'][] = array(
                    'type' => 'literal',
                    'value' => $organizationLogoUrl
                );
            }
        }
        return;
    }

    /**
     * @param $bestMatch
     * @param $mode
     * @return bool
     */
    private function _writeOrganizationMatches($bestMatch, $mode) {
        if (strtolower($mode) === 'vendor') {
            $type  = 'Vendor';
            $predicate = 'creates';
        } elseif (strtolower($mode) === 'customer') {
            $type  = 'Customer';
            $predicate = 'receives';
        } else {
            return false;
        }

        if (isset($bestMatch['quality'])) {
            if ((double)$bestMatch['quality'] > 0.93) {
                // link to proper organization
                $foundUri = $bestMatch['orgUri'];
                $this->_rprtRes[$foundUri][$this::NS_COUNTR . $predicate][] = array(
                    'type' => 'uri',
                    'value' => $this->_reportUri
                );
            } else {
                // write a new resource describing the best but maybe wrong match
                $suggestionUri = $this::NS_BASE . 'suggestion/' . urlencode($bestMatch['orgUri']);
                $this->_rprtRes[$this->_reportUri][$this::NS_TERMS . 'hasSuggestion'][] = array(
                    'type' => 'uri',
                    'value' => $suggestionUri
                );
                $this->_rprtRes[$suggestionUri][$this::NS_TERMS . 'suggestionFor'][] = array(
                    'type' => 'uri',
                    'value' => $this::NS_COUNTR . $type
                );
                $this->_rprtRes[$suggestionUri][EF_RDF_TYPE][] = array(
                    'type' => 'uri',
                    'value' => $this::NS_TERMS . 'Suggestion'
                );
                $this->_rprtRes[$suggestionUri][$this::NS_TERMS . 'matchedOrganizationQuality'][] = array(
                    'type' => 'literal',
                    'value' => $bestMatch['quality'],
                    'datatype' => EF_XSD_DECIMAL
                );
                $this->_rprtRes[$suggestionUri][$this::NS_TERMS . 'bestMatchedOrganization'][] = array(
                    'type' => 'uri',
                    'value' => $bestMatch['orgUri']
                );
                $this->_rprtRes[$suggestionUri][$this::NS_TERMS . 'bestMatchedString'][] = array(
                    'type' => 'literal',
                    'value' => $bestMatch['literal']
                );
            }
        }
        return true;
    }



    public function fetchCounterReport ($interfaceUri) {

       return;
    }

    public function onSushiImportAction ($event) {
        $store  = Erfurt_App::getInstance()->getStore();
        $config = Erfurt_App::getInstance()->getConfig();

        $this->_model       = $event->selectedModel;
        $resourceUri        = $event->resourceUri;

        //TODO add access control
        //TODO add post parameter

        // tells the OntoWiki to not apply the template to this action
        $this->_helper->viewRenderer->setNoRender();
        $this->_helper->layout->disableLayout();

        // TODO Variables must be filled with values found in amsl store or post request
        $params = $this->_getSushiParams($resourceUri);
        $rqstrID = null;
        $rqstrName = null;
        $rqstrMail = null;
        $cstmrID = '12345';
        $startDate = null;
        $endDate = null;
        $fltr = array();

        $dom = new DOMDocument('1.0', 'utf-8');
        $dom->formatOutput = true;
        $envelope = $dom->createElement('soap:Envelope');
        $dom->appendChild($envelope);
        $envelope->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:soap',
            'http://schemas.xmlsoap.org/soap/envelope/'
        );
        $envelope->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:coun',
            'http://www.niso.org/schemas/sushi/counter'
        );
        $envelope->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:sus',
            'http://www.niso.org/schemas/sushi'
        );

        // TODO find candidates that can be outsourced in a seperate method
        $header = $dom->createElement('soap:Header');
        $body = $dom->createElement('soap:Body');
        $reportRequest = $dom->createElement('coun:ReportRequest');
        $reportRequest->setAttribute('ID', '007');
        $reportRequest->setAttribute('Created', date('c'));

        // Data of requestor
        $requestor = $dom->createElement('sus:Requestor');
        $requestorID = $dom->createElement('sus:ID', '012345678-9');
        $requestorName = $dom->createElement('sus:Name', 'Das Zahlenwesen');
        $requestorMail = $dom->createElement('sus:Email', 'zahlenwesen@univ.org');
        // Data of customer
        $customer = $dom->createElement('sus:CustomerReference');
        $customerID = $dom->createElement('sus:ID', $cstmrID);
        $report = $dom->createElement('sus:ReportDefinition');
        $report->setAttribute('Name', 'TestNAME');
        $report->setAttribute('Release', '4.0');

        // Filter
        $filter = $dom->createElement('sus:Filters');
        if (count($fltr) > 0) {
            foreach ($fltr as $key => $filterType) {
                $i = $dom->createElement('sus:Filter');
                $i->setAttribute('Name', $filterType);
                $filter->appendChild($i);
            }
        }
        $usage = $dom->createElement('sus:UsageDateRange');
        $begin = $dom->createElement('sus:Begin',date('Y-m-d'));
        $end = $dom->createElement('sus:End',date('Y-m-d'));

        // Build domtree
        $envelope->appendChild($header);
        $header->appendChild($body);
        $body->appendChild($reportRequest);
        $reportRequest->appendChild($requestor);
        $reportRequest->appendChild($customer);
        $reportRequest->appendChild($report);
        $requestor->appendChild($requestorID);
        $requestor->appendChild($requestorName);
        $requestor->appendChild($requestorMail);
        $customer->appendChild($customerID);
        $report->appendChild($filter);
        $filter->appendChild($usage);
        $usage->appendChild($begin);
        $usage->appendChild($end);

        return;
        // debug;
        //echo $dom->saveXML();
    }

    private function _setAllSushiData () {
        $query = 'SELECT DISTINCT ?sushi ?p ?o  WHERE {' . PHP_EOL ;
        $query.= '?sushi a <' . $this::NS_TERMS . 'SushiSetting> .' . PHP_EOL;
        $query.= '?sushi ?p ?o .' . PHP_EOL;
        $query.= '}' . PHP_EOL;

        $result = $this->_model->sparqlQuery($query);
        if (count($result) > 0) {
            $this->_sushiSettings = $result;
        }

    }

    private function _getSushiParams($resourceUri) {

        if ($this->_sushiSettings !== null && isset($this->_sushiSettings[$resourceUri])) {
            foreach ($this->_sushiSettings as $result) {
                if (isset($result[$resourceUri])) {
                    return $result[$resourceUri];
                }
            }
        }
        return false;
    }
}

