

function uploadCropping() {
    var $uploadCrop = null, $additionalCrops = {};

    var element = $('#avatar_upload');
    var maxWidth = parseInt(element.data('avatar-width'));
    var maxHeight = parseInt(element.data('avatar-height'));

    function readFile(input) {
        if (input.files && input.files[0]) {
            var reader = new FileReader();

            if ($uploadCrop === null) {
                $uploadCrop = $('#avatar_croppie').croppie({
                    enableExif: true,
                    viewport: {
                        width: maxWidth,
                        height: maxHeight
                    },
                    boundary: {
                        width: maxWidth + 50,
                        height: maxHeight + 50
                    }
                });
            }

            $('#avatar_croppie').on('update.croppie', function(ev, cropData) {
                $uploadCrop.croppie('result', { type: 'canvas', size: 'viewport'}).then(function(base64) { $('#avatar_upload input[name="imageblob"]').attr('value', base64); });
                $('#avatar_upload_box').val('');
            });
            
            reader.onload = function (e) {
                $uploadCrop.croppie('bind', {
                    url: e.target.result
                }).then(function(){
                    $('#attached_image').hide();
                });
            }
            
            reader.readAsDataURL(input.files[0]);
        }
    }

    function readAdditionalFile(input) {
        if (input.files && input.files[0]) {
            var reader = new FileReader();

            var index = $(input).closest('button').data('index');
            if (!$additionalCrops.hasOwnProperty('crop' + index)) {
                $additionalCrops['crop' + index] = $(input).closest('.upload-container').find('.avatar-croppie').croppie({
                    enableExif: true,
                    viewport: {
                        width: maxWidth,
                        height: maxHeight
                    },
                    boundary: {
                        width: maxWidth + 50,
                        height: maxHeight + 50
                    }
                });
            }

            $(input).closest('.upload-container').find('.avatar-croppie').on('update.croppie', function(ev, cropData) {
                var index = $(ev.target).closest('.upload-container').find('button').data('index');
                $additionalCrops['crop' + index].croppie('result', { type: 'canvas', size: 'viewport'}).then(function(base64) { $('input[name="additional_blob[' + index + ']"]').attr('value', base64); });
                $('#avatar_upload_box_' + index).val('');
            });
            
            reader.onload = function (e) {
                $additionalCrops['crop' + index].croppie('bind', {
                    url: e.target.result
                }).then(function(){
                    $('#attached_image_' + index).hide();
                });
            }
            
            reader.readAsDataURL(input.files[0]);
        }
    }

    $('#avatar_upload_box').on('change', function () { readFile(this); });

    $('.additional-upload input[type=file]').on('change', function () { readAdditionalFile(this); });
}

document.addEventListener("DOMContentLoaded", (event) => {
  uploadCropping();
});