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
    private $_model = null;
    private $_post = null;
    private $_organizations = null;
    private $_autocompletionData = null;

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

        $this->view->headScript()->appendFile($this->_config->urlBase . 'extensions/counterimporter/templates/counterimporter/js/typeahead.bundle.js');
        $this->view->headScript()->appendFile($this->_config->urlBase . 'extensions/counterimporter/templates/counterimporter/js/search.js');
        $this->view->headLink()->appendStylesheet($this->_config->urlBase . 'extensions/counterimporter/templates/counterimporter/css/counter.css');

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
     * This method searches for organizations and their labels
     * It creates 2 arrays. One can be used for levenshtein matching
     * the other for suggestion engine via javascript
     */
    private function _setOrganizations() {
        if ($this->_model === null) {
            return;https://www.youtube.com/watch?v=8keGzv_SInE
        }

        // Set namespaces
        $nsRdfs     = 'http://www.w3.org/2000/01/rdf-schema#';
        $nsVcard    = 'http://www.w3.org/2006/vcard/ns#';
        $nsFoaf     = 'http://xmlns.com/foaf/0.1/';
        $nsCountr   = 'http://vocab.ub.uni-leipzig.de/counter/';

        $query = 'SELECT DISTINCT *  WHERE {' . PHP_EOL ;
        $query.= '  ?org a <' . $nsFoaf . 'Organization> .' . PHP_EOL;
        $query.= '  OPTIONAL {?org <' . $nsVcard . 'organization-name> ?name .}' . PHP_EOL;
        $query.= '  OPTIONAL {?org <' . $nsRdfs . 'label> ?label .}' . PHP_EOL;
        $query.= '  OPTIONAL {?org <' . $nsCountr . 'hasOrganizationName> ?cntrName .}' . PHP_EOL;
        $query.= '}' . PHP_EOL;

        $result = $this->_model->sparqlQuery($query);

        $organizations = array();
        $temp = array();
        if (!empty($result)) {
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
        }

        $this->_organizations = $organizations;

        // Delete duplicates -> returns an associative array
        // Create a new non associative array
        $json = array();
        foreach ($temp as $value) {
            $json[] = $value;
        }
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
        /*
        $publisherList = array("Ovid Technologies Inc.","Ablex","Academic Press","Addison-Wesley","American Association of University Presses (AAUP)","American Scientific Publishers","Apple Academic Press","Anthem Press","Avon Books ","Ballantine","Bantam Books ","Basic Books ","John Benjamins","Blackwell ","Blackwell Publishers","Cambridge International Science Publishing","Cambridge University Press","Chapman & Hall ","Charles River Media","Collamore ","Columbia University Press ","Cornell Univ Press ","EDP","Ellis Horwood ","Elsevier Science","Erlbaum","Free Press ","W.H.Freeman ","Guilford","Harper Collins ","Harvard University Press","Hemisphere ","Holt","Houghton Mifflin ","Hyperion ","International Universities Press","IOS Press","Karger","Kluwer Academic ","Lawrence Erlbaum","Libertas Academica","McGraw-Hill","Macmillan Publishing","Macmillan Computer Publishing USA","McGraw-Hill","MIT Press","Morgan Kaufman","North Holland","W.W. Norton ","O'Reilly","Oxford University Press","Pantheon ","Penguin","Pergamon Press ","Plenum Publishing","PLOS ","Prentice Hall","Princeton University Press","Psychology Press ","Random House","Rift Publishing House","Routledge ","Rutgers University Press","Scientia Press","Simon & Schuster","Simon & Schuster Interactive","SPIE","Springer Verlag","Stanford University Press","Touchstone ","University of California Press","University of Chicago Press ","Van Nostrand Reinhold ","Wiley","John Wiley","World Scientific Publishing ","Yale University Press 302 Temple St","Yourdon");
        foreach ($publisherList as $org) {
            echo "String1, " . $org . "<br>";
            $match = $this->_matchOrganization($org);
            var_dump($match);
        }
        die();
        */
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

        // Set namespaces
        $nsBase     = 'http://amsl.technology/counter/resource/';
        $nsRdf      = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';
        $nsRdfs     = 'http://www.w3.org/2000/01/rdf-schema#';
        $nsVcard    = 'http://www.w3.org/2006/vcard/ns#';
        $nsDc       = 'http://purl.org/dc/elements/1.1/';
        $nsSkos     = 'http://www.w3.org/2004/02/skos/core#';
        $nsXsd      = 'http://www.w3.org/2001/XMLSchema#';
        $nsFoaf     = 'http://xmlns.com/foaf/0.1/';
        $nsAmsl     = 'http://vocab.ub.uni-leipzig.de/amsl/';
        $nsTerms     = 'http://vocab.ub.uni-leipzig.de/amslTerms/';
        $nsCountr   = 'http://vocab.ub.uni-leipzig.de/counter/';

        // regular expressions
        $regISBN = '/\b(?:ISBN(?:: ?| ))?((?:97[89])?\d{9}[\dx])\b/i';
        $regISSN = '/\d{4}\-\d{3}[\dxX]/';

        // Create Uris
        $reportUri  = $nsBase . 'report/testimport';
        //$reportUri  = $nsBase . 'report/' . md5(rand());

        $rprtRes = array(
            $reportUri => array(
                $nsRdfs . 'type' => array(
                    array('type' => 'uri', 'value' => $nsCountr . 'Report')
                )
            )
        );

        // READING XML file
        $xmlstr     = '';
        $xmlstr     = file_get_contents($file);
        $xml        = new SimpleXMLElement($xmlstr);
        $ns         = $xml->getNamespaces(true);
        $child      = $xml->children("http://www.niso.org/schemas/counter");

        foreach ($child as $out) {
            $report = $out->children("http://www.niso.org/schemas/counter");
            $attributes = $out->attributes();
            // Check if date is a valid date (string methods used)
            if (strlen($attributes->Created > 9)) {
                $substring = substr($attributes->Created,0, 10);
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
                        $rprtRes[$reportUri][$nsCountr . 'wasCreatedOn'][] = array(
                            'type' => 'literal',
                            'value' => $date,
                            'datatype' => $nsXsd . 'date'
                        );
                    }
                } else {
                    $rprtRes[$reportUri][$nsCountr . 'wasCreatedOn'][] = array(
                        'type' => 'literal',
                        'value' => $today,
                        'datatype' => $nsXsd . 'date'
                    );
                }
            }

            $value = (string)$attributes->ID;                                       // Report Id
            if (!(empty($value))) {
                $rprtRes[$reportUri][$nsCountr . 'hasReportID'][] = array(
                        'type' => 'literal',
                        'value' => $value
                );
            }

            $value = (string)$attributes->Version;                             // Report Version
            if (!(empty($value))) {
                $rprtRes[$reportUri][$nsCountr . 'hasReportVersion'][] = array(
                    'type' => 'literal',
                    'value' => $value
                );
            }

            $value = (string)$attributes->Name;                                  // Report Title
            if (!(empty($value))) {
                $rprtRes[$reportUri][$nsCountr . 'hasReportTitle'][] = array(
                    'type' => 'literal',
                    'value' => $value
                );
            }

            //$vendor = $report->Vendor->children("http://www.niso.org/schemas/counter");
            $vendor = $report->Vendor;
            $contact = $vendor->Contact;

            // Vendor data
            $vendorName    = (string)$vendor->Name ;
            $vendorId      = (string)$vendor->ID;
            $vendorWebSite = (string)$vendor->WebSiteUrl;
            $vendorMail    = (string)$contact->{'E-mail'};
            $vendorLogoUrl = (string)$vendor->LogoUrl;
            if (!(empty($vendorId))) {
                $vendorUri = $nsCountr . 'vendor/' . urlencode($vendorId);
            } elseif (!(empty($vendorName))) {
                $vendorUri = $nsCountr . 'vendor/' . urlencode($vendorName);
            }
            $vndrRes[$vendorUri][$nsRdfs . 'type'][] = array(
                'type' => 'uri',
                'value' => $nsCountr . 'Vendor'
            );

            $vndrRes[$vendorUri][$nsRdfs . 'type'][] = array(
                'type' => 'uri',
                'value' => $nsFoaf . 'Organization'
            );

            $vndrRes[$vendorUri][$nsCountr . 'creates'][] = array(
                'type' => 'uri',
                'value' => $reportUri
            );

            if (!(empty($vendorId))) {
                $vndrRes[$vendorUri][$nsCountr . 'hasOrganizationID'][] = array(
                    'type' => 'literal',
                    'value' => $vendorId
                );
            };

            if (!(empty($vendoName))) {
                $vndrRes[$vendorUri][$nsSkos . 'altLabel'][] = array(
                    'type' => 'literal',
                    'value' => $vendorName
                );

                $vndrRes[$vendorUri][$nsVcard . 'organization-name'][] = array(
                    'type' => 'literal',
                    'value' => $vendorName
                );
            };

            if (!(empty($vendorWebSite))) {
                $vndrRes[$vendorUri][$nsVcard . 'hasURL'][] = array(
                    'type' => 'literal',
                    'value' => $vendorWebSite
                );
            };

            if (!(empty($vendorMail))) {
                if (!(substr($vendorMail, 0, 7) === 'mailto:')) {
                    $vendorMail = 'mailto:' . $vendorMail;
                }
                // TODO Evtl. noch auf URI (Errfurt) überprüfen, allerdings weiß ich nicht,
                // ob mailto erkannt wird
                $vndrRes[$vendorUri][$nsVcard . 'hasEmail'][] = array(
                    'type' => 'uri',
                    'value' => $vendorMail
                );
            };
            if (!(empty($vendorLogoUrl))) {
                $vndrRes[$vendorUri][$nsCountr . 'hasLogoUrl'][] = array(
                    'type' => 'literal',
                    'value' => $vendorLogoUrl
                );
            };

            // Custumor data
            $customer = $report->Customer;
            $contact = $customer->Contact;
            $customerName = (string)$customer->Name;
            $customerID   = (string)$customer->ID;
            $customerWebSite = (string)$customer->WebSiteUrl;
            $customerMail    = (string)$contact->{'E-mail'};
            $customerLogoUrl = (string)$customer->LogoUrl;

            // Find a customer URI
            $foundCustomerUri = '';
            if (!(empty($customerName))) {
                $customerUri = $nsBase . 'customer/' . urlencode($customerName);
                // TODO: write link from matched resource to report resource
                $bestMatch = $this->_matchOrganization($customerName);
                if ((double)$bestMatch['quality'] > 0.95) {
                    $foundCustomerUri = $bestMatch['orgUri'];
                }
            } else {
                if (!(empty($customerId))) {
                    $customerUri = $nsBase . 'customer/' . urlencode($customerId);
                } elseif (!(empty($customerMail) && empty($customerWebSite) &&
                    empty($customerLogoUrl))
                ) {
                    $customerUri = $nsBase . 'customer/' . md5(rand());
                } else {
                    $customerUri = '';
                }
            }

            // Write customer statements
            if (!(empty($customerUri))) {
                $cstmrRes[$customerUri][$nsRdfs . 'type'][] = array(
                    'type' => 'uri',
                    'value' => $nsFoaf . 'Organization'
                );

                $cstmrRes[$customerUri][$nsRdfs . 'type'][] = array(
                    'type' => 'uri',
                    'value' => $nsCountr . 'Customer'
                );

                $cstmrRes[$customerUri][$nsCountr . 'receives'][] = array(
                    'type' => 'uri',
                    'value' => $reportUri
                );

                if (!(empty($foundCustomerUri))) {
                    $cstmrRes[$foundCustomerUri][$nsCountr . 'receives'][] = array(
                        'type' => 'uri',
                        'value' => $reportUri
                    );
                }

                $cstmrRes[$customerUri][$nsSkos . 'altLabel'][] = array(
                    'type' => 'literal',
                    'value' => $customerName . ' [COUNTER]'
                );

                if (!(empty($customerWebSite))) {
                    $cstmrRes[$customerUri][$nsVcard . 'hasURL'][] = array(
                        'type' => 'literal',
                        'value' => $customerWebSite
                    );
                };

                if (!(empty($customerID))) {
                    $cstmrRes[$customerUri][$nsCountr . 'hasOrganizationID'][] = array(
                        'type' => 'literal',
                        'value' => $customerID
                    );
                };

                if (!(empty($customerMail))) {
                    if (!(substr($customerMail, 0, 7) === 'mailto:')) {
                        $customerMail = 'mailto:' . $customerMail;
                    }
                    // TODO Evtl. noch auf URI (Errfurt) überprüfen, allerdings weiß ich nicht,
                    // ob mailto erkannt wird
                    $cstmrRes[$customerUri][$nsVcard . 'hasEmail'][] = array(
                        'type' => 'uri',
                        'value' => $customerMail
                    );
                };

                if (!(empty($customerLogoUrl))) {
                    $cstmrRes[$customerUri][$nsCountr . 'hasLogoUrl'][] = array(
                        'type' => 'literal',
                        'value' => $customerLogoUrl
                    );
                }
            }

            // Report Items
            foreach ($customer->ReportItems as $reportItem) {
                $itemName = (string)$reportItem->ItemName;
                if (!(empty($itemName))) {
                    $itemUri = $nsBase . 'reportitem/' . urlencode($itemName);
                } else {
                    $itemUri = $nsBase . 'reportitem/' . md5(rand());
                }

                $itemRes[$itemUri][$nsRdfs . 'type'][] = array(
                    'type' => 'uri',
                    'value' => $nsCountr . 'ReportItem'
                );

                if (!(empty($itemName))) {
                    $itemRes[$itemUri][$nsRdfs . 'label'][] = array(
                        'type' => 'literal',
                        'value' => $itemName
                    );
                }

                $itemRes[$itemUri][$nsCountr . 'isContainedIn'][] = array(
                    'type' => 'uri',
                    'value' => $reportUri
                );

                $platform = (string)$reportItem->ItemPlatform;

                if (!(empty($platform))) {
                    $platformUri = $nsBase . 'platform/' . urlencode($platform);
                    $pltfrmRes[$platformUri][$nsRdfs . 'type'][] = array(
                                    'type' => 'uri',
                                    'value' => $nsAmsl . 'Platform'
                    );

                    $pltfrmRes[$platformUri][$nsSkos . 'altLabel'][] = array(
                        'type' => 'literal',
                        'value' => $platform
                    );

                    $itemRes[$itemUri][$nsCountr . 'isAccessibleVia'][] = array(
                        'type' => 'uri',
                        'value' => $platformUri
                    );
                }

                $itemPublisher = (string)$reportItem->ItemPublisher;
                if (!(empty($itemPublisher))) {
                    // TODO Match
                    $publisherUri = $nsBase . 'publisher/' . urlencode($itemPublisher);
                    $pblshrRes[$publisherUri][$nsRdfs . 'type'][] = array(
                        'type' => 'uri',
                        'value' => $nsCountr . 'Publisher'
                    );

                    $itemRes[$itemUri][$nsDc . 'publisher'][] = array(
                        'type' => 'uri',
                        'value' => $publisherUri
                    );
                }

                $itemDataType = (string)$reportItem->ItemDataType;
                if (!(empty($itemPublisher))) {
                    $itemRes[$itemUri][$nsCountr . 'hasItemDataType'][] = array(
                        'type' => 'uri',
                        'value' => $nsCountr . $itemDataType
                    );
                }

                foreach ($reportItem->ItemIdentifier as $itemIdentifier) {
                    $itemIdValue = (string)$itemIdentifier->Value;
                    $itemIdType = (string)$itemIdentifier->Type;
                    if (!(empty($itemIdValue))) {
                        if (!(empty($itemIdType))) {
                            switch (strtolower($itemIdType)) {
                                case 'doi':
                                    $pred = $nsAmsl . 'doi';
                                    if (substr($itemIdValue, 0, 3) === '10.') {
                                        $uri = 'http://doi.org/' . $itemIdValue;
                                    } else {
                                        $uri = $nsBase . 'noValueGiven';
                                    }
                                    break;
                                case 'online_issn':
                                    $pred = $nsAmsl . 'eissn';
                                    if (preg_match($regISSN, $itemIdValue)) {
                                        $uri = 'urn:ISSN' . $itemIdValue;
                                    }
                                    break;
                                case 'print_issn':
                                    $pred = $nsAmsl . 'pissn';
                                    if (preg_match($regISSN, $itemIdValue)) {
                                        $uri = 'urn:ISSN' . $itemIdValue;
                                    }
                                    break;
                                case 'online_isbn':
                                    $pred = $nsAmsl . 'eisbn';
                                    if (preg_match($regISBN, $itemIdValue)) {
                                        $uri = 'urn:ISBN' . $itemIdValue;
                                    }
                                    break;
                                case 'print_isbn':
                                    $pred = $nsAmsl . 'pisbn';
                                    if (preg_match($regISBN, $itemIdValue)) {
                                        $uri = 'urn:ISBN' . $itemIdValue;
                                    }
                                    break;
                                case 'proprietaryID':
                                    $pred = $nsAmsl . 'proprietaryId';
                                    if (preg_match($regISBN, $itemIdValue)) {
                                        $uri = $nsBase . 'ProprietaryID/' . $itemIdValue;
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
                    $pubUri = $nsBase . urlencode($pubYr);
                    $pubYear[$pubYr][$nsRdfs . 'type'][] = array(
                        'type' => 'uri',
                        'value' => $pubUri
                    );
                } else {
                    if (!(empty($pubYrFrom)) && !(empty($pubYrTo))) {
                        $pubDateGiven = true;
                        $pubUri = $nsBase . urlencode($pubYrFrom . $$pubYrTo);
                        $pubYear[$pubYr][$nsRdfs . 'type'][] = array(
                            'type' => 'uri',
                            'value' => $pubUri
                        );
                    } else {
                        $pubUri = '';
                    }
                }

                // save date ranges to link to them from instances during
                // another foreach loop at located at same xml hierarchy level
                foreach ($reportItem->ItemPerformance as $itemPerformance) {
                    $perfCategory = (string)$itemPerformance->Category;
                    $start = (string)$itemPerformance->Period->Begin;
                    $end = (string)$itemPerformance->Period->End;
                    // TODO Annika fragen, ob gewollt ist, dass man dann auf der URI bündelt
                    $dateRangeUri = $nsBase . 'datarange/' . urlencode($start . $end);
                    $rprtRes[$reportUri][$nsCountr . 'hasPerformance'][] = array(
                        'type' => 'uri',
                        'value' => $dateRangeUri
                    );
                    $dateRangeRes[$dateRangeUri][$nsRdfs . 'type'][] = array(
                                    'type' => 'uri',
                                    'value' => $nsCountr . 'DateRange'
                    );

                    foreach ($itemPerformance->Instance as $instance) {
                        $instanceUri = $nsBase . 'countinginstance/' . md5(rand());
                        $metricType = (string)$instance->MetricType;
                        $count = (string)$instance->Count;

                        // link from report item resource
                        $itemRes[$itemUri][$nsCountr . 'hasPerformance'][] = array(
                            'type' => 'uri',
                            'value' => $instanceUri
                        );

                        // write counting instance
                        $cntngInstance[$instanceUri][$nsRdfs . 'type'][] = array(
                            'type' => 'uri',
                            'value' => $nsCountr . 'CountingInstance'
                        );

                        if (!(empty($pubUri))) {
                            $cntngInstance[$instanceUri][$nsCountr . 'considersPubYear'][] = array(
                                'type' => 'uri',
                                'value' => $pubUri
                            );
                        }

                        $cntngInstance[$instanceUri][$nsCountr . 'measureForPeriod'][] = array(
                            'type' => 'uri',
                            'value' => $dateRangeUri
                        );

                        if (!(empty($perfCategory))) {
                            $cntngInstance[$instanceUri][$nsCountr . 'hasCategory'][] = array(
                                'type' => 'uri',
                                'value' => $nsCountr . 'category/' . $perfCategory
                            );
                        }

                        $cntngInstance[$instanceUri][$nsCountr . 'hasMetricType'][] = array(
                            'type' => 'uri',
                            'value' => $nsBase . 'metrictype/' . $metricType
                        );

                        $cntngInstance[$instanceUri][$nsCountr . 'hasCount'][] = array(
                            'type' => 'literal',
                            'value' => $count,
                            "datatyp" => $nsXsd . 'date'
                        );
                    }
                    // --- End Item Performance ---
                }

                // --- End Report-Item ---
            }

            $bestMatch = $this->_matchOrganization($vendorName);

            if (isset($bestMatch['quality'])) {
                if ((double)$bestMatch['quality'] > 0.93) {
                    $foundVendorUri = $bestMatch['orgUri'];
                    $rprtRes[$foundVendorUri][$nsCountr . 'creates'][] = array(
                        'type' => 'uri',
                        'value' => $reportUri
                    );
                } /*else {
                    $rprtRes[$reportUri][$nsTerms . 'matchedOrganizationQuality'][] = array(
                        array(
                            'type' => 'literal',
                            'value' => $bestMatch['quality'],
                            'datatype' => $nsXsd . 'decimal'
                        )
                    );
                    $rprtRes[$reportUri][$nsTerms . 'bestMatchedOrganization'][] = array(
                        array(
                            'type' => 'uri',
                            'value' => $bestMatch['orgUri']
                        )
                    );
                    $rprtRes[$reportUri][$nsTerms . 'bestMatchedString'][] = array(
                        array(
                            'type' => 'literal',
                            'value' => $bestMatch['literal']
                        )
                    );
                }*/
            }
        }

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
            $this->_model->addMultipleStatements($rprtRes);
            $this->_model->addMultipleStatements($itemRes);
            $this->_model->addMultipleStatements($pltfrmRes);
            $this->_model->addMultipleStatements($vndrRes);
            $this->_model->addMultipleStatements($cstmrRes);
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

    private function _import($fileOrUrl, $locator)
    {
        $modelIri = (string)$this->_model;
        $versioning = $this->_erfurt->getVersioning();
        // action spec for versioning
        $actionSpec = array();
        $actionSpec['type'] = 11;
        $actionSpec['modeluri'] = $modelIri;
        $actionSpec['resourceuri'] = $modelIri;

        try {
            // starting action
            $versioning->startAction($actionSpec);
            $this->_erfurt->getStore()->importRdf($modelIri, $fileOrUrl, 'ttl', $locator);
            // stopping action
            $versioning->endAction(); 
            // Trigger Reindex
            $indexEvent = new Erfurt_Event('onFullreindexAction');
            $indexEvent->trigger();
        } catch (Erfurt_Exception $e) {
            // re-throw
            throw new OntoWiki_Controller_Exception(
                'Could not import given model: ' . $e->getMessage(),
                0,
                $e
            );
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
        $nsVcard    = 'http://www.w3.org/2006/vcard/ns#';
        $nsRdfs     = 'http://www.w3.org/2000/01/rdf-schema#';
        $nsCountr   = 'http://vocab.ub.uni-leipzig.de/counter/';

        if ($this->_organizations !== null) {
            $lev4All[$nsVcard . 'organization-name'] = 0;
            $lev4All[$nsRdfs . 'label'] = 0;
            $lev4All[$nsCountr . 'hasOrganizationName'] = 0;
            $bestMatch['quality'] = 0;

            foreach ($this->_organizations as $organization) {
                if (!(empty($organization['name']))) {
                    $lev4All[$nsVcard . 'organization-name'] = $this->_relLevenshtein(
                        $organization['name'], $orgName);
                    if ($lev4All[$nsVcard . 'organization-name'] >= $bestMatch['quality']) {
                        $bestMatch['quality'] = $lev4All[$nsVcard . 'organization-name'];
                        $bestMatch['property'] = $nsVcard . 'organization-name';
                        $bestMatch['literal'] = $organization['name'];
                        $bestMatch['orgUri'] = $organization['org'];
                    }
                }
                if (!empty($organization['label'])) {
                    $lev4All[$nsRdfs . 'label'] = $this->_relLevenshtein(
                        $organization['label'], $orgName);
                    if ($lev4All[$nsRdfs . 'label'] >= $bestMatch['quality']) {
                        $bestMatch['quality'] = $lev4All[$nsRdfs . 'label'];
                        $bestMatch['property'] = $nsRdfs . 'label';
                        $bestMatch['literal'] = $organization['label'];
                        $bestMatch['orgUri'] = $organization['org'];
                    }
                }
                if (!empty($organization['cntrName'])) {
                    $lev4All[$nsCountr . 'hasOrganizationName'] = $this->_relLevenshtein(
                        $organization['cntrName'], $orgName);
                    if ($lev4All[$nsCountr . 'hasOrganizationName'] >= $bestMatch['quality']) {
                        $bestMatch['quality'] = $lev4All[$nsCountr . 'hasOrganizationName'];
                        $bestMatch['property'] = $nsCountr . 'hasOrganizationName';
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
}
