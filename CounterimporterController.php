<?php
/**
 * This file is part of the {@link http://ontowiki.net OntoWiki} project.
 *
 * @copyright Copyright (c) 2014, {@link http://amsl.technology}
 * @license   http://opensource.org/licenses/gpl-license.php GNU General Public License (GPL)
 */

    require realpath(dirname(__FILE__)) . '/../fulltextsearch/libraries/vendor/autoload.php';
/**
 * Controller for OntoWiki Basicimporter Extension
 *
 * @category OntoWiki
 * @package  Extensions_Issnimporter
 * @author   Norman Radtke <radtke@ub.uni-leipzig.de>
 */
class CounterimporterController extends OntoWiki_Controller_Component
{
    private $_model                = null;
    private $_translate            = null;
    private $_organizationUri      = null;
    private $_organizations        = null;
    private $_organizationJSONData = null;
    private $_sushiSettings        = null;
    private $_sushiJSONData        = null;
    private $_rprtRes              = null;
    private $_reportUri            = null;

    // some namespaces
    const NS_AMSL   = 'http://vocab.ub.uni-leipzig.de/amsl/';
    const NS_SUSHI  = 'http://vocab.ub.uni-leipzig.de/sushi/';
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

        $this->_owApp     = OntoWiki::getInstance();
        $this->_model     = $this->_owApp->selectedModel;
        $this->_translate = $this->_owApp->translate;

        // add a standard toolbar
        $toolbar = $this->_owApp->toolbar;
        $toolbar->appendButton(
            OntoWiki_Toolbar::SUBMIT,
            array('name' => $this->_translate->translate('Import Data'), 'id' => 'importdata')
        )->appendButton(
            OntoWiki_Toolbar::RESET,
            array('name' => $this->_translate->translate('Cancel'), 'id' => 'importdata')
        );
        $this->view->placeholder('main.window.toolbar')->set($toolbar);

        // setup the navigation
        OntoWiki::getInstance()->getNavigation()->reset();
        OntoWiki::getInstance()->getNavigation()->register(
            'sushipicker',
            array(
                'controller' => 'counterimporter',
                'action' => 'sushixml',
                'name' => $this->_translate->translate('Import COUNTER data with SUSHI'),
                'position' => 0,
                'active' => true
            )
        );

        OntoWiki::getInstance()->getNavigation()->register(
            'xmluploader',
            array(
                'controller' => 'counterimporter',
                'action' => 'counterxml',
                'name' => $this->_translate->translate('Upload a COUNTER XML file'),
                'position' => 1,
                'active' => false
            )
        );

    }

    /**
     * This action will fetch COUNTER XML with help of SUSHI protocol
     * After fetching the XML
     */
    public function sushixmlAction()
    {
        $this->view->placeholder('main.window.title')->set('Please select your SUSHI vendor');
        if ($this->_request->isPost()) {
            $post = $this->_request->getPost();
            $sushiUri = $post['sushi-input'];
            if ($this->_isDate($post['from'])) {
                $start = $post['from'];
            } else {
                $msg = $this->_translate->translate(
                    'The given start date is empty or wrong. Please use the calendar widget.'
                );
                $this->_owApp->appendErrorMessage($msg);
            }

            if ($this->_isDate($post['to'])) {
            $end = $post['to'];
            } else {
                $msg = $this->_translate->translate(
                    'The given end date is empty or wrong. Please use the calendar widget.'
                );
                $this->_owApp->appendErrorMessage($msg);
            }

            if (Erfurt_Uri::check($sushiUri)) {
                if (isset($start) && isset($end)) {
                    if (new DateTime($start) < new DateTime($end)) {
                        $this->_sushiImport($sushiUri, $start, $end);
                    } else {
                        $msg = $this->_translate->translate(
                            'The end date lies before the start date.'
                        );
                        $this->_owApp->appendErrorMessage($msg);
                    }
                }
            } else {
                $msg = $this->_translate->translate(
                    'The given URI is not valid. Please check if there are typos and try again.'
                );
                $this->_owApp->appendErrorMessage($msg);
            }
        }
        return;
    }

    /**
     * The COUNTER XML upload method. Uploads a COUNTER xml file and calls import method
     */
    public function counterxmlAction()
    {
        $this->view->placeholder('main.window.title')->set('Upload a counter xml file');

        if ($this->_request->isPost()) {
            $post = $this->_request->getPost();
            $this->_organizationUri = $post['organization-input'];
            $upload = new Zend_File_Transfer();
            $filesArray = $upload->getFileInfo();

            $message = '';
            switch (true) {
                case empty($filesArray):
                    $message = $this->_translate->translate(
                        'The upload went wrong. check post_max_size in your php.ini or ask your IT.'
                    );
                    break;
                case ($filesArray['source']['error'] == UPLOAD_ERR_INI_SIZE):
                    $message = $message = $this->_translate->translate(
                        'The uploaded files\'s size exceeds the upload_max_filesize directive in php.ini.'
                    );
                    break;
                case ($filesArray['source']['error'] == UPLOAD_ERR_PARTIAL):
                    $message = $message = $this->_translate->translate(
                        'The file was only partially uploaded.')
                    ;
                    break;
                case ($filesArray['source']['error'] >= UPLOAD_ERR_NO_FILE):
                    $message = $message = $this->_translate->translate(
                        'Please select a file to upload.'
                    );
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
            $xmlstr = file_get_contents($file);
            $this->_prepareXml ($xmlstr);
        } else {
            return;
        }
    }

    /**
     * This action will return a json_encoded array containing SUSHI lookup data for JS
     */
    public function getsushiAction()
    {
        // tells the OntoWiki to not apply the template to this action
        $this->_helper->viewRenderer->setNoRender();
        $this->_helper->layout->disableLayout();

        if ($this->_sushiJSONData === null) {
            $this->_setSushiJSONData();
        }

        $this->_response->setBody($this->_sushiJSONData);
    }

    /**
     * This action will return a json_encoded array containing organization lookup data for JS
     */
    public function getorganizationsAction()
    {
        // tells the OntoWiki to not apply the template to this action
        $this->_helper->viewRenderer->setNoRender();
        $this->_helper->layout->disableLayout();

        if ($this->_organizationJSONData === null) {
            $this->_setOrganizationJSONData();
        }

        $this->_response->setBody($this->_organizationJSONData);
    }

    /**
     * This method expects a XML string containing COUNTER data
     * @param $xmlstr
     */
    private function _prepareXml ($xmlstr) {
        // READING XML file
        $xmlstr = str_replace('xmlns=', 'ns=', $xmlstr);
        $xml = new SimpleXMLElement($xmlstr);
        $ns = $xml->getDocNamespaces(true);
        $ns2 = $xml->getNamespaces(true);
        foreach($xml->children() as $node) {
            echo '';
        }
        $counterUrl = 'http://www.niso.org/schemas/counter';
        $sushiUrl = 'http://www.niso.org/schemas/sushi/counter';
        $ns = $xml->getDocNamespaces(true);
        if (count($ns) != 0) {
            if (in_array($counterUrl, $ns)) {
                $flippedNs = array_flip($ns);
                $counterNS = $flippedNs[$counterUrl];
                $xml->registerXPathNamespace($counterNS, $counterUrl);
            }
            if (in_array($sushiUrl, $ns)) {
                $flippedNs = array_flip($ns);
                $sushiNS = $flippedNs[$sushiUrl];
                $xml->registerXPathNamespace($sushiNS, $sushiUrl);
            }

        }

        $error = $xml->xpath('//Message');
        if (count($error) > 0) {
            $out = 'The returned XML contains information that may help you: ' ;
            foreach ($error as $key => $message) {
                $out .= '"' . (string)$message . '"' . PHP_EOL;
            }
        }

        $reportsFound = false;

        // Try to find a report node in counter namespace
        $reports = $xml->children($counterUrl);
        if (count($reports) !== 0) {
            if (isset($reports->Report)) {
                $reportsFound = true;
                foreach ($reports->Report as $report) {
                    $attributes = $report->attributes();
                    $this->_writeReport($report, $attributes);
                }
            }
        }

        if ($reportsFound === false) {
            $reports = $xml->children();
            if (count($reports) !== 0) {
                if (isset($reports->Report)) {
                    $reportsFound = true;
                    foreach ($reports->Report as $report) {
                        $attributes = $report->attributes();
                        $this->_writeReport($report, $attributes);
                    }
                }
            }
        }

        // If no success try to find reports with XPATH using namespaces used in xml
        if ($reportsFound === false && isset($counterNS)) {
            $reports = $xml->xpath('//' . $counterNS . ':Report');
            if (!(count($reports) === 0 || $reports === null)) {
                foreach ($reports as $report) {
                    $reportsFound = true;
                    // we are a Report node
                    if ($report->attributes() !== null) {
                        $attributes = $report->attributes();
                        $report = $report->children($counterUrl);
                        $dataWritten = $this->_writeReport($report, $attributes);
                    }
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
                            $report = $report->children();
                            $dataWritten = $this->_writeReport($report, $attributes);
                        }
                    }
                }
            }
        }

        // If we reach this point, no COUNTER reports were found in XML
        // We throw a message and exit
        if ($reportsFound === false) {
            $this->_owApp->appendErrorMessage($message = $this->_translate->translate(
                'Nothing imported. No report data found.'
            ));
            if (isset($out)) {
                $this->_owApp->appendErrorMessage($out);
            }
            return;
        }

        // $reportFound === true
        // import statements

        // starting action
        $modelIri = (string)$this->_model;
        $versioning = $this->_erfurt->getVersioning();
        // action spec for versioning
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
            $this->_owApp->appendErrorMessage($message = $this->_translate->translate(
                'Could not import counter xml: ') . $message);
            return;
        }

        $this->_owApp->appendSuccessMessage($message = $this->_translate->translate(
            'Data successfully imported.'
        ));
    }

    private function _writeReport($report, $attributes)
    {
        // regular expressions
        $regISBN = '/\b(?:ISBN(?:: ?| ))?((?:97[89])?\d{9}[\dx])\b/i';
        $regISSN = '/\d{4}\-\d{3}[\dxX]/';

        // create report uri
        $id = (string)$attributes->ID;                                           // Report Id
        $name = (string)$attributes->Name;
        $pre = $this::NS_BASE . 'report/';
        if ($id !== '') {
            $this->_reportUri = $pre . urlencode($id);
        } elseif ($name !== '') {
            $this->_reportUri = $pre . urlencode($name);
        } else {
            $msg = $message = $this->_translate->translate(
                'Import aborted. No report identifier found in COUNTER data.'
            );
            $this->_owApp->appendErrorMessage($msg);
            return false;
        }

        $this->_rprtRes[$this->_reportUri][EF_RDF_TYPE][] = array(
            'type' => 'uri',
            'value' => $this::NS_COUNTR . 'Report'
        );

        // Check if date is a valid date (string methods used)
        if (strlen($attributes->Created > 9)) {
            $date = substr($attributes->Created, 0, 10);
            if ($this->_isDate($date)) {
                $this->_rprtRes[$this->_reportUri][$this::NS_COUNTR . 'wasCreatedOn'][] =
                    array(
                        'type' => 'literal',
                        'value' => $date,
                        'datatype' => $this::NS_XSD . 'date'
                    );
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
            $identifiers = array();
            foreach ($reportItem->ItemIdentifier as $itemIdentifier) {
                $itemIdValue = (string)$itemIdentifier->Value;
                $itemIdType = (string)$itemIdentifier->Type;
                if (!(empty($itemIdValue))) {
                    if (!(empty($itemIdType))) {
                        switch (strtolower($itemIdType)) {
                            case 'doi':
                                if (substr($itemIdValue, 0, 3) === '10.') {
                                    $identifiers['doi'] = 'http://doi.org/' . $itemIdValue;
                                }
                                break;
                            case 'online_issn':
                                if (preg_match($regISSN, $itemIdValue)) {
                                    $identifiers['eissn'] = 'urn:ISSN:' . $itemIdValue;
                                }
                                break;
                            case 'print_issn':
                                if (preg_match($regISSN, $itemIdValue)) {
                                    $identifiers['pissn'] = 'urn:ISSN:' . $itemIdValue;
                                }
                                break;
                            case 'online_isbn':
                                if (preg_match($regISBN, $itemIdValue)) {
                                    $identifiers['eisbn'] = 'urn:ISBN:' . $itemIdValue;
                                }
                                break;
                            case 'print_isbn':
                                if (preg_match($regISBN, $itemIdValue)) {
                                    $identifiers['pisbn'] = 'urn:ISBN:' . $itemIdValue;
                                }
                                break;
                            case 'proprietary':
                                    $base = $this::NS_BASE . 'ProprietaryID/';
                                    $identifiers['proprietaryId'] = $base . $itemIdValue;
                                break;
                        }
                    }
                }
            }

            // assuming that a journal won't have an ISBN as a book won't an ISSN, we build the uri
            // of the report item with help of their IDs by the following order:
            // ISSN||ISBN > DOI > name of report element > md5sum (in case no identifiers found)
            $idForUri = '';
            if (isset($identifiers['doi'])) {
                $idForUri = $identifiers['doi'];
            }
            if (isset($identifiers['pissn'])) {
                $idForUri = $identifiers['pissn'];
            }
            if (isset($identifiers['eissn'])) {
                $idForUri = $identifiers['eissn'];
            }
            if (isset($identifiers['pisbn'])) {
                $idForUri = $identifiers['pisbn'];
            }
            if (isset($identifiers['eisbn'])) {
                $idForUri = $identifiers['eisbn'];
            }
            $itemName = (string)$reportItem->ItemName;
            if ($idForUri === '') {
                if (!(empty($itemName))) {
                    $idForUri = $itemName;
                } else {
                    $idForUri = md5(rand());
                }
            }
            $itemUri = $this::NS_BASE . 'reportitem/' . urlencode($idForUri);

            // write statements for report item
            if (isset($identifiers)) {
                foreach ($identifiers as $identifier => $value) {
                    //echo 'Identifier: '. $identifier . ' value: ' . $value . '</br>';
                    $this->_rprtRes[$itemUri][$this::NS_AMSL . $identifier][] = array(
                        'type' => 'uri',
                        'value' => urlencode($value)
                    );
                }
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
     * This method searches for organizations and their different kind of labels
     */
    private function _setOrganizations() {
        if ($this->_model === null) {
            return;
        }

        $query = 'SELECT *  WHERE {' . PHP_EOL ;
        $query.= '  ?org a <' . $this::NS_FOAF . 'Organization> .' . PHP_EOL;
        $query.= '  ?s <' . $this::NS_AMSL . 'licensor>  ?org .' . PHP_EOL;
        $query.= '  OPTIONAL {?org <' . $this::NS_VCARD . 'organization-name> ?name .}' . PHP_EOL;
        $query.= '  OPTIONAL {?org <' . EF_RDFS_LABEL . '> ?label .}' . PHP_EOL;
        $query.= '  OPTIONAL {?org <' . $this::NS_COUNTR . 'hasOrganizationName> ?cntrName .}' . PHP_EOL;
        $query.= '  OPTIONAL {?contract <' . $this::NS_AMSL . 'licensor> ?org .}' . PHP_EOL;
        $query.= '}' . PHP_EOL;

        $result = $this->_model->sparqlQuery($query);

        $organizations = array();
        if (count($result) > 0) {
            foreach ($result as $key => $organization) {
                // Write data used for matching
                $organizations[$organization['org']]['org'] = $organization['org'];

                if (!(empty($organization['cntrName']))) {
                    $organizations[$organization['org']]['cntrName'][] = $organization['cntrName'];
                }

                if (!(empty($organization['label']))) {
                    $organizations[$organization['org']]['label'][] = $organization['label'];
                }

                if (!(empty($organization['name']))) {
                    $organizations[$organization['org']]['name'][] = $organization['name'];
                }
            }
        }
        $this->_organizations = $organizations;
    }

    private function _setOrganizationJSONData () {
        if ($this->_organizations === null) {
            $this->_setOrganizations();
        }

        $temp = array();
        foreach ($this->_organizations as $key => $value) {
            foreach ($value as $key2 =>$labels) {
                if ($key2 !== 'org' && (count($labels) > 0)) {
                    foreach ($labels as $label) {
                        $temp[] = array(
                            'org' => $key,
                            'name' => $label
                        );
                    }
                }
            }
        }

        // Delete duplicates -> returns an associative array
        $temp = $this->_super_unique($temp);
        // Create a new non associative array
        $json = array();
        foreach ($temp as $value) {
            $json[] = $value;
        }
        $this->_organizationJSONData = json_encode($json);
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

    private function _sushiImport ($resourceUri, $startDate, $endDate ) {
        $sushiData = $this->_getSushiParams($resourceUri);
        $sushiUrl = $sushiData[$this::NS_SUSHI . 'hasSushiUrl'];
        $rqstrID = $sushiData[$this::NS_SUSHI . 'hasSushiRequestorID'];
        $rqstrName = $sushiData[$this::NS_SUSHI . 'hasSushiRequestorName'];
        $rqstrMail = $sushiData[$this::NS_SUSHI . 'hasSushiRequestorMail'];
        $cstmrID = $sushiData[$this::NS_SUSHI . 'hasSushiCustomerID'];
        $rprtName = $sushiData[$this::NS_SUSHI . 'hasSushiReportName'][0];
        $rprtRelease = $sushiData[$this::NS_SUSHI . 'hasSushiReportRelease'];
        if (!(isset($sushiData['http://vocab.ub.uni-leipzig.de/terms/hasAmslLicensor']))) {
            $msg = $message = $this->_translate->translate(
                'No organization found that corresponds to this SUSHI data.'
            );
            $this->_owApp->appendErrorMessage($msg);
            $msg = $message = $this->_translate->translate(
                "Please check if the resource $resourceUri has a statement terms:hasAmslLicensor."
            );
            $this->_owApp->appendInfoMessage($msg);
            return;
        } else {
            $this->_organizationUri = $sushiData['http://vocab.ub.uni-leipzig.de/terms/hasAmslLicensor'];
        }
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
            'xmlns:count',
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
        $reportRequest = $dom->createElement('count:ReportRequest');
        $reportRequest->setAttribute('ID', 'Counter Report Request');
        $reportRequest->setAttribute('Created', date('c'));

        // Data of requestor
        $requestor = $dom->createElement('sus:Requestor');
        $requestorID = $dom->createElement('sus:ID', $rqstrID);
        $requestorName = $dom->createElement('sus:Name', $rqstrName);
        $requestorMail = $dom->createElement('sus:Email', $rqstrMail);
        // Data of customer
        $customer = $dom->createElement('sus:CustomerReference');
        $customerID = $dom->createElement('sus:ID', $cstmrID);
        $report = $dom->createElement('sus:ReportDefinition');
        $report->setAttribute('Name', $rprtName);
        $report->setAttribute('Release', $rprtRelease);

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

        // Split requests per month
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        $interval = new DateInterval('P1M');
        $period = new DatePeriod($start,$interval,$end);

        foreach ($period as $month) {
            $begin = $dom->createElement('sus:Begin', $month->format('Y-m-01'));
            $end = $dom->createElement('sus:End', $month->format('Y-m-t'));

            // Build domtree
            $envelope->appendChild($header);
            $envelope->appendChild($body);
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
            while($usage->hasChildNodes()) {
                $usage->removeChild($usage->firstChild);
            }
            $usage->appendChild($begin);
            $usage->appendChild($end);

            $xml = $dom->saveXML();

            /*$options = array(
                'Content-Type' => 'text/xml',
                'Connectoin' => 'keep-alive',
                'proxy' => 'proxy.uni-leipzig.de'
                'proxyport' => '3128'
                );
            $response = Requests::post($sushiUrl, $options, $xml);

            if ($response->success === true) {
                $this->_prepareXml($response->body);
            } else {
                $msg = 'The request went wrong. HTTP response with status:  ';
                $msg.= $response->status_code;
                $this->_owApp->appendErrorMessage($msg);
                $msg = 'You may analyze the returned XML file to adjust your SUSHI settings: ';
                $msg.= $response->body;
                $this->_owApp->appendErrorMessage($msg);
                return;
            }
            */
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $sushiUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: text/xml'));
            curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            //curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_PROXY, 'proxy.uni-leipzig.de');
            curl_setopt($ch, CURLOPT_PROXYPORT, '3128');
            $ch_result[] = curl_exec($ch);
            curl_close($ch);
        }

        foreach ($ch_result as $response) {
            $this->_prepareXml($response);
        }
    }

    private function _setAllSushiData () {
        $query = 'SELECT DISTINCT *  WHERE {' . PHP_EOL ;
        $query.= '?s a <' . $this::NS_SUSHI . 'SushiSetting> .' . PHP_EOL;
        $query.= '?s ?p ?o .' . PHP_EOL;
        $query.= '}' . PHP_EOL;

        $result = $this->_model->sparqlQuery($query);
        if (count($result) > 0) {
            $this->_sushiSettings = $result;
        }
        return;
    }

    private function _setSushiJSONData () {
        $query = 'SELECT DISTINCT *  WHERE {' . PHP_EOL ;
        $query.= '?s a <' . $this::NS_SUSHI . 'SushiSetting> .' . PHP_EOL;
        //$query.= '?s <' . $this::NS_SUSHI . 'hasSushiCustomerID> ' . '?customerID .' . PHP_EOL;
        //$query.= '?s <' . $this::NS_SUSHI . 'hasSushiRequestorID> ' . '?requestorID .' . PHP_EOL;
        //$query.= '?s <' . $this::NS_SUSHI . 'hasSushiRequestorMail> ' . '?requestorMail .' . PHP_EOL;
        //$query.= '?s <' . $this::NS_SUSHI . 'hasSushiRequestorName> ' . '?requestorName .' . PHP_EOL;
        //$query.= '?s <' . $this::NS_SUSHI . 'hasSushiUrl> ' . '?url .' . PHP_EOL;
        //$query.= '?s <' . $this::NS_SUSHI . 'hasSushiReportName> ' . '?reportName .' . PHP_EOL;
        $query.= '?s <' . EF_RDFS_LABEL   . '> ' . '?label .' . PHP_EOL;
        $query.= '}' . PHP_EOL;

        $result = $this->_model->sparqlQuery($query);
        // Delete duplicates -> returns an associative array
        $temp = $this->_super_unique($result);
        // Create a new non associative array
        $json = array();
        foreach ($temp as $value) {
            $json[] = $value;
        }
        $this->_sushiJSONData = json_encode($json);
    }

    private function _getSushiParams($resourceUri) {

        if ($this->_sushiSettings === null ) {
            $this->_setAllSushiData();
        }

        $temp = array();
        foreach ($this->_sushiSettings as $result) {
            if ($result['s'] === $resourceUri) {
                switch ($result['p']) {
                    case $this::NS_SUSHI . 'hasSushiReportName':
                        $temp[$result['p']][] = $result['o'];
                        break;
                    default:
                        $temp[$result['p']] = $result['o'];
                }
            }
        }

        return $temp;
    }

    private function _isDate ($date) {
        if (strlen($date) >= 10) {
            $year = substr($date, 0, 4);
            $hyphen1 = substr($date, 4, 1);
            $month = substr($date, 5, 2);
            $hyphen2 = substr($date, 7, 1);
            $day = substr($date, 8, 2);
            $test = $hyphen1 . $hyphen2;
            $dateIsNumeric = false;
            if (is_numeric($year) && is_numeric($month) && is_numeric($day)) {
                $dateIsNumeric = true;
            }
            if ($dateIsNumeric === true) {
                if (checkdate($month, $day, $year) === true && $test === '--') {
                    return true;
                }
            }
        }
        return false;
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
}
