/**
 * Callbacks for plupload.
 *
 */

Drupal.filedepot_multiupload = Drupal.filedepot_multiupload || {};

Drupal.filedepot_multiupload.stateChangedCallback = function (up, files) {
  if(up.state === 2){
    var parentFolder = jQuery("#edit-filedepot-parentfolder").val();
    if(parentFolder <= 0){
      up.stop();
      jQuery("#edit-filedepot-parentfolder").addClass("error");
      alert(Drupal.t("You must select a valid folder"));
    }
  }
};

Drupal.filedepot_multiupload.beforeUploadCallback = function (up, files) {
  var parentFolder = jQuery("#edit-filedepot-parentfolder").val();
  up.settings.url = up.settings.url + "&cid=" + parentFolder;
}

Drupal.filedepot_multiupload.uploadCompleteCallback = function (up, files) {
  location.reload();
};
