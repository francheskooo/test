<?php

/**
 * Implementation of filedepot_multiupload_ajax()
 *
 * Main ajax handler for the module
 */

ob_start();

// Definitions of allowed token values.
define('PLUPLOAD_HANDLE_UPLOADS', 'plupload_handle_uploads');

/**
 * Implementation of filedepot_multiupload_dispatcher()
 *
 * Does the actual uploading of the file
 *
 * @param string $action the action to perform
 */
function filedepot_multiupload_dispatcher($action) {
  global $user;

  $filedepot = filedepot_filedepot();

  switch ($action) {
    case 'upload_files':
      if ($_GET['plupload_token'] == drupal_get_token(PLUPLOAD_HANDLE_UPLOADS)) {

        $filedepot = filedepot_filedepot();
        module_load_include('php', 'filedepot', 'lib-common');

        $cid = (int) $_GET['cid'];
        // Retrieve the upload location - parent folder node id.
        $folder_nid = db_query("SELECT nid FROM {filedepot_categories} WHERE cid=:cid", array(':cid' => $cid))->fetchField();
        $node  = node_load($folder_nid);
        $cid_perms = $filedepot->getPermissionObject($cid);

        if ($cid_perms->canUpload() === FALSE) {
          drupal_set_message(t('Insufficient privileges to upload to this folder'), 'error');
          return;
        }
        watchdog("filedepot_test", var_export($cid_perms, TRUE));
        // Admin's have all perms so test for users with upload moderated approval only.
        if ($cid_perms->canUploadDirect() === FALSE) {
          $moderated = TRUE;
          $private_destination = 'private://filedepot/' . $node->folder . '/submissions/';
        }
        else {
          $moderated = FALSE;
          $private_destination = 'private://filedepot/' . $node->folder . '/';
        }

        // Best to call file_prepare_directory() - even if you believe directory exists.
        file_prepare_directory($private_destination, FILE_CREATE_DIRECTORY);

        $file = plupload_file_uri_to_object($_FILES['file']['tmp_name']);
		$filename = $_FILES['file']['name'];
        $ext_parts = explode(".", $filename);
        $ext       = end($ext_parts);

        $original_filename = $filename;
        // Save record in submission table and set status to 0 -- not online.
        if ($moderated) {
          // Generate random file name for newly submitted file to hide it until approved.
          $charset           = "abcdefghijklmnopqrstuvwxyz";
          $moderated_tmpname = '';
          for ($i = 0; $i < 12; $i++) {
            $moderated_tmpname .= $charset[(mt_rand(0, (drupal_strlen($charset) - 1)))];
          }
          $moderated_tmpname .= '.' . $ext;

          $private_uri    = $private_destination . $moderated_tmpname;
          $file           = file_move($file, $private_uri, FILE_EXISTS_RENAME);
          $file->filename = $original_filename;

          $filetitle = $original_filename;

          $filename = str_replace("filedepot/{$node->folder}/", '', $file->uri);

          // Update folder node - add the file as an attachment.
          $file->display     = 1;
          $file->description = '';

          // Doing node_save changes the file status to permanent in the file_managed table.
          $node->filedepot_folder_file[LANGUAGE_NONE][] = (array) $file;
          node_save($node);

          $query = db_insert('filedepot_filesubmissions');
          $query->fields(array(
            'cid',
            'fname',
            'tempname',
            'title',
            'description',
            'drupal_fid',
            'version_note',
            'size',
            'mimetype',
            'extension',
            'submitter',
            'date',
            'tags',
            'notify'
          ));
          $query->values(array(
            'cid'          => $node->folder,
            'fname'        => $file->filename,
            'tempname'     => $moderated_tmpname,
            'title'        => $filetitle,
            'description'  => '',
            'drupal_fid'   => $file->fid,
            'version_note' => '',
            'size'         => $file->filesize,
            'mimetype'     => $_FILES['file']['type'],
            'extension'    => $ext,
            'submitter'    => $user->uid,
            'date'         => time(),
            'tags'         => '',
            'notify'       => 1,
          ));
          $newrecid      = $query->execute();
          if ($newrecid > 0) {
            // Get id for the new file record.
            $newrecid = db_query_range("SELECT id FROM {filedepot_filesubmissions} WHERE cid=:cid AND submitter=:uid ORDER BY id DESC", 0, 1, array(':cid' => $node->folder, ':uid' => $user->uid))->fetchField();
            filedepot_sendNotification($newrecid, FILEDEPOT_NOTIFY_ADMIN);
          }
          else {
            drupal_set_message(t('Issue saving new file - invalid new file submissions record'), 'warning');
          }
        }
        else {
          $private_uri = $private_destination . $_FILES['file']['name'];
          $file        = file_move($file, $private_uri, FILE_EXISTS_RENAME);

          $ext_parts   = explode(".", $_FILES['file']['name']);
          $ext         = end($ext_parts);

          // Get name of new file in case it was renamed after the file_move().
          $filename = str_replace("filedepot/{$node->folder}/", '', $_FILES['file']['name']);
          $filetitle = $original_filename;

          // Update folder node - add the file as an attachment.
          $file->display     = 1;
          $file->description = '';

          // Doing node_save changes the file status to permanent in the file_managed table
          $node->filedepot_folder_file[LANGUAGE_NONE][] = (array) $file;
          node_save($node);

          // Update the file usage table.
          file_usage_add($file, 'filedepot', 'node', $node->nid);

          // Create filedepot record for file and set status of file to online.
          $query = db_insert('filedepot_files');
          $query->fields(array(
            'cid',
            'fname',
            'title',
            'description',
            'version',
            'drupal_fid',
            'size',
            'mimetype',
            'extension',
            'submitter',
            'status',
            'date'
          ));
          $query->values(array(
            'cid'         => $node->folder,
            'fname'       => $filename,
            'title'       => $filetitle,
            'description' => '',
            'version'     => 1,
            'drupal_fid'  => $file->fid,
            'size'        => $file->filesize,
            'mimetype'    => $_FILES['file']['type'],
            'extension'   => $ext,
            'submitter'   => $user->uid,
            'status'      => 1,
            'date'        => time(),
          ));
          $newrecid     = $query->execute();
          if ($newrecid > 0) {
            $query = db_insert('filedepot_fileversions');
            $query->fields(array(
              'fid',
              'fname',
              'drupal_fid',
              'version',
              'notes',
              'size',
              'date',
              'uid',
              'status'
            ));
            $query->values(array(
              'fid'        => $newrecid,
              'fname'      => $filename,
              'drupal_fid' => $file->fid,
              'version'    => 1,
              'notes'      => '',
              'size'       => $file->filesize,
              'date'       => time(),
              'uid'        => $user->uid,
              'status'     => 1,
            ));
            $query->execute();

            // Update related folders last_modified_date.
            $workspace_parent_folder = filedepot_getTopLevelParent($node->folder);
            filedepot_updateFolderLastModified($workspace_parent_folder);
          }
          else {
            drupal_set_message(t('Invalid id returned from insert new file record'), 'error');
          }

          // Need to clear the cache
          // as the node will still have the original file name.
          field_cache_clear();
        }
      }
      ctools_include('ajax');
      ctools_add_js('ajax-responder');
      ctools_ajax_command_reload();
      break;
  }
  ob_clean();
}
ob_end_flush();
