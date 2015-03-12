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
 * @author   Sebastian Tramp <mail@sebastian.tramp.name>
 */
class CounterimporterController extends OntoWiki_Controller_Component
{
    private $_model              = null;
    private $_post               = null;
    private $_organizations      = null;
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
        $today = date("Y-m-d");

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

        // regular expressions
        $regISBN = '/\b(?:ISBN(?:: ?| ))?((?:97[89])?\d{9}[\dx])\b/i';
        $regISSN = '/\d{4}\-\d{3}[\dxX]/';

        // create report uri
        $this->_reportUri  = $this::NS_BASE . 'report/' . md5(rand());

        $this->_rprtRes = array(
            $this->_reportUri => array(
                EF_RDF_TYPE => array(
                    array(
                        'type' => 'uri',
                        'value' => $this::NS_COUNTR . 'Report'
                    )
                )
            )
        );

        // READING XML file
        $xmlstr     = file_get_contents($file);
        $xml        = new SimpleXMLElement($xmlstr);
        $child      = $xml->children("http://www.niso.org/schemas/counter");

        foreach ($child as $out) {
            $report = $out->children("http://www.niso.org/schemas/counter");
            $attributes = $out->attributes();
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
                        'value' => $today,
                        'datatype' => $this::NS_XSD . 'date'
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
            }

                                                                                      // Vendor data
            $vendor = $report->Vendor;
            $vndrRes = $this->_writeOrganizationData($vendor, 'Vendor');

                                                                                    // Custumor data
            $customer = $report->Customer;
            $cstmrRes = $this->_writeOrganizationData($customer, 'Customer');

                                                                                     // Report Items
            foreach ($customer->ReportItems as $reportItem) {
                $itemName = (string)$reportItem->ItemName;
                if (!(empty($itemName))) {
                    $itemUri = $this::NS_BASE . 'reportitem/' . urlencode($itemName);
                } else {
                    $itemUri = $this::NS_BASE . 'reportitem/' . md5(rand());
                }

                $itemRes[$itemUri][EF_RDF_TYPE][] = array(
                    'type' => 'uri',
                    'value' => $this::NS_COUNTR . 'ReportItem'
                );

                if (!(empty($itemName))) {
                    $itemRes[$itemUri][EF_RDFS_LABEL][] = array(
                        'type' => 'literal',
                        'value' => $itemName
                    );
                }

                $itemRes[$itemUri][$this::NS_COUNTR . 'isContainedIn'][] = array(
                    'type' => 'uri',
                    'value' => $this->_reportUri
                );

                $platform = (string)$reportItem->ItemPlatform;

                if (!(empty($platform))) {
                    $platformUri = $this::NS_BASE . 'platform/' . urlencode($platform);
                    $pltfrmRes[$platformUri][EF_RDF_TYPE][] = array(
                        'type' => 'uri',
                        'value' => $this::NS_COUNTR . 'Platform'
                    );

                    $pltfrmRes[$platformUri][$this::NS_SKOS . 'altLabel'][] = array(
                        'type' => 'literal',
                        'value' => $platform
                    );

                    $itemRes[$itemUri][$this::NS_COUNTR . 'isAccessibleVia'][] = array(
                        'type' => 'uri',
                        'value' => $platformUri
                    );
                }

                $itemPublisher = (string)$reportItem->ItemPublisher;
                if (!(empty($itemPublisher))) {
                    // TODO Match
                    $publisherUri = $this::NS_BASE . 'publisher/' . urlencode($itemPublisher);
                    $pblshrRes[$publisherUri][EF_RDF_TYPE][] = array(
                        'type' => 'uri',
                        'value' => $this::NS_COUNTR . 'Publisher'
                    );

                    $itemRes[$itemUri][$this::NS_DC . 'publisher'][] = array(
                        'type' => 'uri',
                        'value' => $publisherUri
                    );
                }

                $itemDataType = (string)$reportItem->ItemDataType;
                if (!(empty($itemPublisher))) {
                    $itemRes[$itemUri][$this::NS_COUNTR . 'hasItemDataType'][] = array(
                        'type' => 'uri',
                        'value' => $this::NS_COUNTR . $itemDataType
                    );
                }

                foreach ($reportItem->ItemIdentifier as $itemIdentifier) {
                    $itemIdValue = (string)$itemIdentifier->Value;
                    $itemIdType = (string)$itemIdentifier->Type;
                    if (!(empty($itemIdValue))) {
                        if (!(empty($itemIdType))) {
                            switch (strtolower($itemIdType)) {
                                case 'doi':
                                    $pred = $this::NS_AMSL . 'doi';
                                    if (substr($itemIdValue, 0, 3) === '10.') {
                                        $uri = 'http://doi.org/' . $itemIdValue;
                                    } else {
                                        $uri = $this::NS_BASE . 'noValueGiven';
                                    }
                                    break;
                                case 'online_issn':
                                    $pred = $this::NS_AMSL . 'eissn';
                                    if (preg_match($regISSN, $itemIdValue)) {
                                        $uri = 'urn:ISSN:' . $itemIdValue;
                                    }
                                    break;
                                case 'print_issn':
                                    $pred = $this::NS_AMSL . 'pissn';
                                    if (preg_match($regISSN, $itemIdValue)) {
                                        $uri = 'urn:ISSN:' . $itemIdValue;
                                    }
                                    break;
                                case 'online_isbn':
                                    $pred = $this::NS_AMSL . 'eisbn';
                                    if (preg_match($regISBN, $itemIdValue)) {
                                        $uri = 'urn:ISBN:' . $itemIdValue;
                                    }
                                    break;
                                case 'print_isbn':
                                    $pred = $this::NS_AMSL . 'pisbn';
                                    if (preg_match($regISBN, $itemIdValue)) {
                                        $uri = 'urn:ISBN:' . $itemIdValue;
                                    }
                                    break;
                                case 'proprietaryID':
                                    $pred = $this::NS_AMSL . 'proprietaryId';
                                    if (preg_match($regISBN, $itemIdValue)) {
                                        $uri = $this::NS_BASE . 'ProprietaryID/' . $itemIdValue;
                                    }
                                    break;
                            }
                            $itemRes[$itemUri][$pred][] = array(
                                'type' => 'uri',
                                'value' => $uri
                            );
                        }
                    }
                }

                $pubYr = (string)$reportItem->ItemPerformance->PubYr;
                $pubYrFrom = (string)$reportItem->ItemPerformance->PubYrFrom;
                $pubYrTo = (string)$reportItem->ItemPerformance->PubYrTo;
                if (!(empty($pubYr))) {
                    $pubUri = $this::NS_BASE . urlencode($pubYr);
                    $pubYear[$pubYr][EF_RDF_TYPE][] = array(
                        'type' => 'uri',
                        'value' => $pubUri
                    );
                } else {
                    if (!(empty($pubYrFrom)) && !(empty($pubYrTo))) {
                        $pubUri = $this::NS_BASE . urlencode($pubYrFrom . $$pubYrTo);
                        $pubYear[$pubYr][EF_RDF_TYPE][] = array(
                            'type' => 'uri',
                            'value' => $pubUri
                        );
                    } else {
                        $pubUri = '';
                    }
                }

                // save date ranges to link to them from instances during
                // another foreach loop located at same xml hierarchy level
                foreach ($reportItem->ItemPerformance as $itemPerformance) {
                    $perfCategory = (string)$itemPerformance->Category;
                    $start = (string)$itemPerformance->Period->Begin;
                    $end = (string)$itemPerformance->Period->End;
                    // TODO Annika fragen, ob gewollt ist, dass man dann auf der URI bündelt
                    $dateRangeUri = $this::NS_BASE . 'datarange/' . urlencode($start . $end);
                    $this->_rprtRes[$this->_reportUri][$this::NS_COUNTR . 'hasPerformance'][] = array(
                        'type' => 'uri',
                        'value' => $dateRangeUri
                    );
                    $this->_rprtRes[$dateRangeUri][EF_RDF_TYPE][] = array(
                        'type' => 'uri',
                        'value' => $this::NS_COUNTR . 'DateRange'
                    );

                    foreach ($itemPerformance->Instance as $instance) {
                        $instanceUri = $this::NS_BASE . 'countinginstance/' . md5(rand());
                        $metricType = (string)$instance->MetricType;
                        $count = (string)$instance->Count;

                        // link from report item resource
                        $itemRes[$itemUri][$this::NS_COUNTR . 'hasPerformance'][] = array(
                            'type' => 'uri',
                            'value' => $instanceUri
                        );

                        // write counting instance
                        $cntngInstance[$instanceUri][EF_RDF_TYPE][] = array(
                            'type' => 'uri',
                            'value' => $this::NS_COUNTR . 'CountingInstance'
                        );

                        if (!(empty($pubUri))) {
                            $cntngInstance[$instanceUri][$this::NS_COUNTR . 'considersPubYear'][] = array(
                                'type' => 'uri',
                                'value' => $pubUri
                            );
                        }

                        $cntngInstance[$instanceUri][$this::NS_COUNTR . 'measureForPeriod'][] = array(
                            'type' => 'uri',
                            'value' => $dateRangeUri
                        );

                        if (!(empty($perfCategory))) {
                            $cntngInstance[$instanceUri][$this::NS_COUNTR . 'hasCategory'][] = array(
                                'type' => 'uri',
                                'value' => $this::NS_COUNTR . 'category/' . $perfCategory
                            );
                        }

                        $cntngInstance[$instanceUri][$this::NS_COUNTR . 'hasMetricType'][] = array(
                            'type' => 'uri',
                            'value' => $this::NS_BASE . 'metrictype/' . $metricType
                        );

                        $cntngInstance[$instanceUri][$this::NS_COUNTR . 'hasCount'][] = array(
                            'type' => 'literal',
                            'value' => $count,
                            "datatype" => EF_XSD_INTEGER
                        );
                    }
                    // --- End Item Performance ---
                }

                // --- End Report-Item ---
            }

        }

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
            $this->_model->addMultipleStatements($itemRes);
            $this->_model->addMultipleStatements($pltfrmRes);
            $this->_model->addMultipleStatements($vndrRes);
            $this->_model->addMultipleStatements($cstmrRes);
            //$this->_model->addMultipleStatements($orgRes);
            $this->_model->addMultipleStatements($cntngInstance);

            if (isset($pubYear)) {
                $this->_model->addMultipleStatements($pubYear);
            }
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

    /**
     * This method searches for organizations and their labels
     * It creates 2 arrays. One can be used for levenshtein matching
     * the other for suggestion engine via javascript
     */
    private function _setOrganizations() {
        if ($this->_model === null) {
            return;
        }

        // Set namespaces

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

                // Write data uses for js suggestions
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
        //$publisherList = array("Ablex","Academic Press","Addison-Wesley","American Association of University Presses (AAUP)","American Scientific Publishers","Apple Academic Press","Anthem Press","Avon Books ","Ballantine","Bantam Books ","Basic Books ","John Benjamins","Blackwell ","Blackwell Publishers","Cambridge International Science Publishing","Cambridge University Press","Chapman & Hall ","Charles River Media","Collamore ","Columbia University Press ","Cornell Univ Press ","EDP","Ellis Horwood ","Elsevier Science","Erlbaum","Free Press ","W.H.Freeman ","Guilford","Harper Collins ","Harvard University Press","Hemisphere ","Holt","Houghton Mifflin ","Hyperion ","International Universities Press","IOS Press","Karger","Kluwer Academic ","Lawrence Erlbaum","Libertas Academica","McGraw-Hill","Macmillan Publishing","Macmillan Computer Publishing USA","McGraw-Hill","MIT Press","Morgan Kaufman","North Holland","W.W. Norton ","O'Reilly","Oxford University Press","Pantheon ","Penguin","Pergamon Press ","Plenum Publishing","PLOS ","Prentice Hall","Princeton University Press","Psychology Press ","Random House","Rift Publishing House","Routledge ","Rutgers University Press","Scientia Press","Simon & Schuster","Simon & Schuster Interactive","SPIE","Springer Verlag","Stanford University Press","Touchstone ","University of California Press","University of Chicago Press ","Van Nostrand Reinhold ","Wiley","John Wiley","World Scientific Publishing ","Yale University Press 302 Temple St","Yourdon");

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
        $contactType         = (string)$contact->Contact;
        $contactMail         = (string)$contact->{'E-mail'};

        // Find a customer URI
        if (!(empty($organizationName))) {
            $organizationUri = $this::NS_BASE . 'organization/' . urlencode($organizationName);
            // TODO: write link from matched resource to report resource
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
            $orgRes[$organizationUri][EF_RDF_TYPE][] = array(
                'type' => 'uri',
                'value' => $this::NS_FOAF . 'Organization'
            );

            $orgRes[$organizationUri][EF_RDF_TYPE][] = array(
                'type' => 'uri',
                'value' => $this::NS_COUNTR . 'Customer'
            );

            $orgRes[$organizationUri][$this::NS_COUNTR . $predicate][] = array(
                'type' => 'uri',
                'value' => $this->_reportUri
            );

            $orgRes[$organizationUri][$this::NS_SKOS . 'altLabel'][] = array(
                'type' => 'literal',
                'value' => $organizationName . ' [COUNTER]'
            );

            $orgRes[$organizationUri][$this::NS_VCARD . 'organization-name'][] = array(
                'type' => 'literal',
                'value' => $organizationName . ' [COUNTER]'
            );

            if (!(empty($organizationWebSite))) {
                $orgRes[$organizationUri][$this::NS_VCARD . 'hasURL'][] = array(
                    'type' => 'literal',
                    'value' => $organizationWebSite
                );
            };

            if (!(empty($organizationID))) {
                $orgRes[$organizationUri][$this::NS_COUNTR . 'hasOrganizationID'][] = array(
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
                $orgRes[$organizationUri][$this::NS_VCARD . 'hasEmail'][] = array(
                    'type' => 'uri',
                    'value' => $contactMail
                );

                if (!(empty($contactType))) {
                    $orgRes[$contactMail][EF_RDFS_COMMENT][] = array(
                        'type' => 'literal',
                        'value' => $contactType . ' [COUNTER]'
                    );
                } else {
                    $orgRes[$contactMail][EF_RDFS_COMMENT][] = array(
                        'type' => 'literal',
                        'value' => 'No further information given [COUNTER]'
                    );
                }
            };

            if (!(empty($organizationLogoUrl))) {
                $orgRes[$organizationUri][$this::NS_COUNTR . 'hasLogoUrl'][] = array(
                    'type' => 'literal',
                    'value' => $organizationLogoUrl
                );
            }
        }
        return $orgRes;
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

    public function writexmlAction () {
        //TODO add access control
        //TODO add post parameter

        // tells the OntoWiki to not apply the template to this action
        $this->_helper->viewRenderer->setNoRender();
        $this->_helper->layout->disableLayout();

        // TODO Variables must be filled with values found in amsl store or post request
        $rqstrID = null;
        $rqstrName = null;
        $rqstrMail = null;
        $cstmrID = null;
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
}

