<?php
/*-------------------------------------------------------+
| SYSTOPIA Donation Receipts Extension                   |
| Copyright (C) 2013-2014 SYSTOPIA                       |
| Author: B. Endres (endres -at- systopia.de)            |
| http://www.systopia.de/                                |
+--------------------------------------------------------+
| TODO: License                                          |
+--------------------------------------------------------*/

/**
 * This class represents the engine for donation receipt runs
 */
class CRM_Donrec_Logic_Engine {

  /**
   * stores the related snapshot object
   */
  protected $snapshot = NULL;

  /**
   * stores the parameters as given by the user
   *
   * known parameters:
   *  exporters  array(exporter_classes)
   *  bulk       1 or 0 - if 1, accumulative (='bulk') donation receipts should be issued
   *  test       1 or 0 - if 0, the contributions will not actually be marked as 'receipt_issued'
   */
  protected $parameters = array();

  /**
   * will cache initialized instances of the exporters as defined in the parameters
   */
  protected $_exporters = NULL;

  /**
   * Will try to initialize the engine with a snapshot
   * and the given parameters. If anything is wrong,
   * an error message will be returned.
   *
   * @return string with an error message on fail, FALSE otherwise
   */
  public function init($snapshot_id, $params=array(), $testMode = FALSE) {
    $this->parameters = $params;
    $this->snapshot = CRM_Donrec_Logic_Snapshot::get($snapshot_id);
    if ($this->snapshot==NULL) {
      return sprintf(ts("Snapshot [%d] does not exist (any more)!"), $snapshot_id);
    }

    // now, check if it's ours:
    $user_id = CRM_Core_Session::singleton()->get('userID');
    $snapshot_creator_id = $this->snapshot->getCreator();
    if ($user_id != $snapshot_creator_id && (!$testMode)) {
      // load creator name
      $creator = civicrm_api3('Contact', 'getsingle', array('id' => $snapshot_creator_id));
      return sprintf(ts("Snapshot [%d] belongs to user '%s'[%s]!"), $snapshot_id, $creator['display_name'], $snapshot_creator_id);
    }

    // now, if this is supposed to be test mode, there must not be a real status
    if ($this->isTestRun()) {
      $snapshot_status = $this->getSnapshotStatus();
      if ($snapshot_status=='RUNNING') {
        return sprintf(ts("Snapshot [%d] is already processing!"), $snapshot_id);
      } elseif ($snapshot_status=='COMPLETE') {
        return sprintf(ts("Snapshot [%d] is already completed!"), $snapshot_id);
      }
    }

    return FALSE;
  }

  /**
   * Will start a new test run, making sure that everything is clean
   */
  public function resetTestRun() {
    $this->snapshot->resetTestRun();
  }

  /**
   * read and load all exporters
   */
  public function getExporters() {
    if ($this->_exporters != NULL) return $this->_exporters;
    $exporters = array();

    if (!empty($this->parameters['exporters'])) {
      $exporter_names = explode(',', $this->parameters['exporters']);
      foreach ($exporter_names as $exporter_name) {
        // init exporter
        $exporter_class =  CRM_Donrec_Logic_Exporter::getClassForExporter($exporter_name);
        $exporter = new $exporter_class();
        $exporter->init($this);
        $exporters[] = $exporter;
      }
    }

    $this->_exporters = $exporters;
    return $exporters;
  }

  /**
   * execute the next step of a donation receipt run
   *
   * @return array of stats:
   */
  public function nextStep() {

    // check status
    $is_bulk = !empty($this->parameters['bulk']);
    $is_test = !empty($this->parameters['test']);

    // container for log messages
    $logs = array();
    $files = array();

    // get next chunk
    $chunk = $this->snapshot->getNextChunk($is_bulk, $is_test);

    // call exporters
    $exporters = $this->getExporters();
    foreach ($exporters as $exporter) {
      // select action
      if ($chunk==NULL) {
        $result = $exporter->wrapUp($this->snapshot->getId(), $is_test, $is_bulk);
        if (!empty($result['download_name']) && !empty($result['download_url'])) {
          $files[$exporter->getID()] = array($result['download_name'], $result['download_url']);
        }
      } else {
        if ($is_bulk) {
          $result = $exporter->exportBulk($chunk, $this->snapshot->getId(), $is_test);
        } else {
          $result = $exporter->exportSingle($chunk, $this->snapshot->getId(), $is_test);
        }
      }
      if (isset($result['log'])) {
        $logs = array_merge($logs, $result['log']);
      }
    }

    // create donation receipts
    if (!$is_test) {

        $receipt_params = array();
        $bulk_line_ids = array();
        $single_line_ids = array();

        // get all lines per contact
        // and sort them in the respective arrays
        foreach ($chunk as $chunk_id => $chunk_items) {
          // override bulk when there is only one receipt
          if($is_bulk && count($chunk_items) == 1) {
            $single_line_ids[] = $chunk_items[0]['id'];
          }elseif ($is_bulk){
            foreach ($chunk_items as $key => $chunk_item) {
              $bulk_line_ids[$chunk_id][] = $chunk_item['id'];
            }
          }else{
            $single_line_ids[] = $chunk_items['id'];
          }
        }

        // THIS IS BULK PROCESSING
        if (!empty($bulk_line_ids)) {
          foreach($bulk_line_ids as $contact_id => $line_ids) {
            if (CRM_Donrec_Logic_Settings::saveOriginalPDF()) {
              // get pdf file name from snapshot line
              $pdf_file = $this->getPDF($line_ids[0]);
              if ($pdf_file) {
                $file = CRM_Donrec_Logic_File::createPermanentFile($pdf_file, basename($pdf_file), $contact_id);
                if (!empty($file)) {
                  $receipt_params['file_id'] = $file['id'];
                }
              }
            }
            $result = CRM_Donrec_Logic_Receipt::createBulkFromSnapshot($this->snapshot, $line_ids, $receipt_params);
            if(!$result) {
              error_log("de.systopia.donrec: error while creating receipt: " . $receipt_params['is_error']);
            }else{
              unset($receipt_params['file_id']);
            }
          }
        }

        // THIS IS SINGLE PROCESSING
        if (!empty($single_line_ids)) {
          foreach ($single_line_ids as $index => $line_id) {
            if (CRM_Donrec_Logic_Settings::saveOriginalPDF()) {
              // get pdf file name from snapshot line
              $pdf_file = $this->getPDF($line_id);
              if ($pdf_file) {
                $contact_id = 1; // TODO: get contact_id
                $file = CRM_Donrec_Logic_File::createPermanentFile($pdf_file, basename($pdf_file), $contact_id);
                if (!empty($file)) {
                  $receipt_params['file_id'] = $file['id'];
                }

                // update PDF path
                $this->setPDF($line_id, $file['path']);
              }
            }
            CRM_Donrec_Logic_Receipt::createSingleFromSnapshot($this->snapshot, $line_id, $receipt_params);
            unset($receipt_params['file_id']);
          }
        }
    }

    // mark the chunk as processed
    if ($chunk) {
      $this->snapshot->markChunkProcessed($chunk, $is_test, $is_bulk);
    }

    // compile and return stats
    $stats = $this->createStats();
    $stats['log'] = $logs;
    $stats['files'] = $files;
    if ($chunk==NULL) {
      $stats['progress'] = 100.0;
    } else {
      $stats['chunk_size'] = count($chunk);
    }

    return $stats;
  }

  /**
   * simply check, if this run is -by parameter- a test run
   */
  public function isTestRun() {
    return !empty($this->parameters['test']);
  }

  /**
   * check what state the snapshot is in
   *
   * @return possible results:
   *  'INIT':     freshly created snapshot
   *  'TESTING':  there is a test ongoing
   *  'TESTED':   there was a test and it's complete
   *  'RUNNING':  the process is still ongoing
   *  'COMPLETE': the process is complete
   */
  public function getSnapshotStatus($states=NULL) {
    $total_count = 0;
    if ($states==NULL) $states = $this->snapshot->getStates();
    foreach ($states as $state => $count) {
      $total_count += $count;
    }

    if ($states['NULL'] == $total_count) return 'INIT';
    if ($states['DONE'] > 0) {
      if ($states['DONE'] == $total_count) {
        return 'COMPLETE';
      } else {
        return 'RUNNING';
      }
    } else {
      if ($states['TEST'] == $total_count) {
        return 'TESTED';
      } else {
        return 'TESTING';
      }
    }
  }

  /**
   * generates a set of statistics on the current state
   *
   * @return array:
   *           'progress'  float value [0..100] giving the progress in %
   *            'status'    @see getSnapshotStatus()
   *            etc.
   */
  public function createStats() {
    $stats = array();
    $states = $this->snapshot->getStates();

    // TODO: Implement settings or dynamics
    $chunk_proportion = 80.0;  // the other 20% are wrap up

    $stats['count'] = 0;
    foreach ($states as $state => $count) $stats['count'] += $count;
    $stats['completed_test'] = $states['TEST'];
    $stats['progress_test'] = $stats['completed_test'] * $chunk_proportion / (float) $stats['count'];
    $stats['completed_real'] = $states['DONE'];
    $stats['progress_real'] = $stats['completed_real'] * $chunk_proportion / (float) $stats['count'];

    $mode = ($this->isTestRun())?'test':'real';
    $stats['mode'] = $mode;
    $stats['completed'] = $stats['completed_'.$mode];
    $stats['progress'] = $stats['progress_'.$mode];

    $stats['status'] = $this->getSnapshotStatus();
    return $stats;
  }

  /**
   * get the retained snapshot object
   */
  public function getSnapshot() {
    return $this->snapshot;
  }


  /**
  * get or create a pdf file for the snapshot line
  */
  public function getPDF($snapshot_line_id) {
    $proc_info = $this->snapshot->getProcessInformation($snapshot_line_id);
    $filename = isset($proc_info['PDF']['pdf_file']) ? $proc_info['PDF']['pdf_file'] : FALSE;
    return $filename;
  }

  /**
  * get or create a pdf file for the snapshot line
  */
  public function setPDF($snapshot_line_id, $file) {
    $proc_info = $this->snapshot->getProcessInformation($snapshot_line_id);
    $proc_info['PDF']['pdf_file'] = $file;
    $this->snapshot->updateProcessInformation($snapshot_line_id, $proc_info);
  }
}
