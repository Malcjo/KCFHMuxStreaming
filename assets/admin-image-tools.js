(function () {
  'use strict';

  function initialiseClientImageFraming() {
    const previewFrame = document.getElementById('kcfhClientCropPreviewFrame');
    const previewImage = document.getElementById('kcfhClientCropPreview');
    const emptyMessage = document.getElementById('kcfhClientCropEmpty');
    const horizontalInput = document.getElementById('kcfh_image_position_x');
    const verticalInput = document.getElementById('kcfh_image_position_y');
    const horizontalValue = document.getElementById('kcfhImagePositionXValue');
    const verticalValue = document.getElementById('kcfhImagePositionYValue');
    const zoomInput = document.getElementById('kcfh_image_zoom');
    const zoomValue = document.getElementById('kcfhImageZoomValue');
    const resetButton = document.getElementById('kcfhResetImagePosition');
    const featuredImageBox = document.getElementById('postimagediv');

    if (
      !previewFrame ||
      !previewImage ||
      !emptyMessage ||
      !horizontalInput ||
      !verticalInput ||
      !zoomInput
    ) {
      return;
    }

    function clampZoom(value) {
      const numberValue = Number(value);
      if (!Number.isFinite(numberValue)) {
        return 100;
      }

      return Math.min(200, Math.max(100, Math.round(numberValue)));
    }

    function clampPercentage(value) {
      const numberValue = Number(value);
      if (!Number.isFinite(numberValue)) {
        return 50;
      }

      return Math.min(100, Math.max(0, Math.round(numberValue)));
    }

    function updatePositionPreview() {
      const horizontalPosition = clampPercentage(horizontalInput.value);
      const verticalPosition = clampPercentage(verticalInput.value);
      const zoomPercentage = clampZoom(zoomInput.value);

      horizontalInput.value = String(horizontalPosition);
      verticalInput.value = String(verticalPosition);
      zoomInput.value = String(zoomPercentage);
      previewImage.style.objectPosition = horizontalPosition + '% ' + verticalPosition + '%';
      previewImage.style.transformOrigin = horizontalPosition + '% ' + verticalPosition + '%';
      previewImage.style.transform = 'scale(' + (zoomPercentage / 100) + ')';

      if (horizontalValue) {
        horizontalValue.textContent = horizontalPosition + '%';
      }

      if (verticalValue) {
        verticalValue.textContent = verticalPosition + '%';
      }

      if (zoomValue) {
        zoomValue.textContent = zoomPercentage + '%';
      }
    }

    function findFeaturedImageUrl() {
      if (!featuredImageBox) {
        return '';
      }

      const selectedImage = featuredImageBox.querySelector(
        '#set-post-thumbnail img, .inside img.attachment-post-thumbnail, .inside img'
      );

      if (!selectedImage) {
        return '';
      }

      return selectedImage.currentSrc || selectedImage.src || '';
    }

    function synchroniseFeaturedImage() {
      const featuredImageUrl = findFeaturedImageUrl();

      if (featuredImageUrl) {
        previewImage.src = featuredImageUrl;
        previewFrame.hidden = false;
        emptyMessage.hidden = true;
      } else {
        previewImage.removeAttribute('src');
        previewFrame.hidden = true;
        emptyMessage.hidden = false;
      }

      updatePositionPreview();
    }

    horizontalInput.addEventListener('input', updatePositionPreview);
    verticalInput.addEventListener('input', updatePositionPreview);
    zoomInput.addEventListener('input', updatePositionPreview);

    resetButton?.addEventListener('click', function () {
      horizontalInput.value = '50';
      verticalInput.value = '50';
      zoomInput.value = '100';
      updatePositionPreview();
    });

    if (featuredImageBox && typeof MutationObserver !== 'undefined') {
      const featuredImageObserver = new MutationObserver(function () {
        window.requestAnimationFrame(synchroniseFeaturedImage);
      });

      featuredImageObserver.observe(featuredImageBox, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['src', 'srcset']
      });
    }

    updatePositionPreview();
  }

  function initialiseDefaultGalleryImage() {
    const imageIdInput = document.getElementById('kcfhDefaultGalleryImageId');
    const previewWrapper = document.getElementById('kcfhDefaultGalleryImagePreview');
    const previewImage = previewWrapper?.querySelector('img');
    const selectButton = document.getElementById('kcfhSelectDefaultGalleryImage');
    const removeButton = document.getElementById('kcfhRemoveDefaultGalleryImage');

    if (
      !imageIdInput ||
      !previewWrapper ||
      !previewImage ||
      !selectButton ||
      !removeButton ||
      !window.wp ||
      !wp.media
    ) {
      return;
    }

    let mediaFrame = null;

    selectButton.addEventListener('click', function (event) {
      event.preventDefault();

      if (!mediaFrame) {
        mediaFrame = wp.media({
          title: 'Choose default gallery image',
          button: {
            text: 'Use as default image'
          },
          library: {
            type: 'image'
          },
          multiple: false
        });

        mediaFrame.on('select', function () {
          const attachment = mediaFrame.state().get('selection').first()?.toJSON();
          if (!attachment) {
            return;
          }

          const previewUrl = attachment.sizes?.medium?.url || attachment.url || '';

          imageIdInput.value = String(attachment.id || '');
          previewImage.src = previewUrl;
          previewWrapper.style.display = '';
          removeButton.style.display = '';
        });
      }

      mediaFrame.open();
    });

    removeButton.addEventListener('click', function (event) {
      event.preventDefault();
      imageIdInput.value = '';
      previewImage.removeAttribute('src');
      previewWrapper.style.display = 'none';
      removeButton.style.display = 'none';
    });
  }

  function initialiseAdminImageTools() {
    initialiseClientImageFraming();
    initialiseDefaultGalleryImage();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialiseAdminImageTools);
  } else {
    initialiseAdminImageTools();
  }
}());