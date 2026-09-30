(function () {
  'use strict';

  function parseTimecode(value) {
    const cleanedValue = String(value || '').trim();
    if (!cleanedValue) {
      return null;
    }

    if (/^\d+(\.\d+)?$/.test(cleanedValue)) {
      return Number(cleanedValue);
    }

    const timeParts = cleanedValue.split(':').map((part) => part.trim());
    if (timeParts.length < 2 || timeParts.length > 3) {
      return null;
    }

    const secondsPart = Number(timeParts.pop());
    const minutesPart = Number(timeParts.pop());
    const hoursPart = timeParts.length ? Number(timeParts.pop()) : 0;

    if (
      !Number.isFinite(hoursPart) ||
      !Number.isFinite(minutesPart) ||
      !Number.isFinite(secondsPart) ||
      hoursPart < 0 ||
      minutesPart < 0 ||
      minutesPart >= 60 ||
      secondsPart < 0 ||
      secondsPart >= 60
    ) {
      return null;
    }

    return (hoursPart * 3600) + (minutesPart * 60) + secondsPart;
  }

  function formatTimecode(totalSeconds) {
    const safeSeconds = Math.max(0, Number(totalSeconds) || 0);
    let wholeSeconds = Math.floor(safeSeconds);
    let milliseconds = Math.round((safeSeconds - wholeSeconds) * 1000);

    if (milliseconds === 1000) {
      wholeSeconds += 1;
      milliseconds = 0;
    }

    const hours = Math.floor(wholeSeconds / 3600);
    const minutes = Math.floor((wholeSeconds % 3600) / 60);
    const seconds = wholeSeconds % 60;

    return [hours, minutes, seconds]
      .map((part) => String(part).padStart(2, '0'))
      .join(':') + '.' + String(milliseconds).padStart(3, '0');
  }

  function initialiseVodEditor() {
    const player = document.getElementById('kcfhVodEditorPlayer');
    const startInput = document.getElementById('kcfhClipStart');
    const endInput = document.getElementById('kcfhClipEnd');
    const setStartButton = document.getElementById('kcfhSetClipStart');
    const setEndButton = document.getElementById('kcfhSetClipEnd');
    const previewButton = document.getElementById('kcfhPreviewClip');
    const trimForm = document.getElementById('kcfhVodTrimForm');
    const timeline = document.getElementById('kcfhTrimTimeline');
    const trimTrack = document.getElementById('kcfhTrimTrack');
    const trimSelection = document.getElementById('kcfhTrimSelection');
    const startHandle = document.getElementById('kcfhTrimStartHandle');
    const endHandle = document.getElementById('kcfhTrimEndHandle');
    const startLabel = document.getElementById('kcfhTrimStartLabel');
    const endLabel = document.getElementById('kcfhTrimEndLabel');
    const selectedDurationLabel = document.getElementById('kcfhSelectedDuration');

    if (
      !player ||
      !startInput ||
      !endInput ||
      !trimForm ||
      !timeline ||
      !trimTrack ||
      !trimSelection ||
      !startHandle ||
      !endHandle
    ) {
      return;
    }

    const videoDuration = Number(timeline.dataset.duration) || 0;
    const minimumClipDuration = 0.5;
    let previewEndTime = null;
    let previewAnimationFrame = null;

    function clamp(value, minimum, maximum) {
      return Math.min(Math.max(value, minimum), maximum);
    }

    function getStartTime() {
      return Number(startHandle.value) || 0;
    }

    function getEndTime() {
      return Number(endHandle.value) || videoDuration;
    }

    function bringHandleToFront(handleToRaise) {
      startHandle.style.zIndex = handleToRaise === startHandle ? '4' : '3';
      endHandle.style.zIndex = handleToRaise === endHandle ? '4' : '3';
    }

    function stopPreviewMonitor() {
      if (previewAnimationFrame !== null) {
        window.cancelAnimationFrame(previewAnimationFrame);
        previewAnimationFrame = null;
      }
    }

    function finishTrimmedPreview() {
      if (previewEndTime === null) {
        return;
      }

      const finalPreviewTime = previewEndTime;
      previewEndTime = null;
      stopPreviewMonitor();
      player.pause();
      player.currentTime = finalPreviewTime;
    }

    function checkTrimmedPreviewBoundary() {
      if (previewEndTime === null) {
        stopPreviewMonitor();
        return;
      }

      if (player.currentTime >= previewEndTime - 0.04) {
        finishTrimmedPreview();
        return;
      }

      previewAnimationFrame = window.requestAnimationFrame(
        checkTrimmedPreviewBoundary
      );
    }

    function startPreviewMonitor() {
      stopPreviewMonitor();
      previewAnimationFrame = window.requestAnimationFrame(
        checkTrimmedPreviewBoundary
      );
    }

    function playTrimmedPreview() {
      const startTime = getStartTime();
      const endTime = getEndTime();

      if (endTime <= startTime) {
        window.alert('Choose a valid trim start and end point first.');
        return;
      }

      stopPreviewMonitor();
      player.pause();
      previewEndTime = endTime;
      player.currentTime = startTime;

      window.requestAnimationFrame(function () {
        const playPromise = player.play();

        if (playPromise && typeof playPromise.catch === 'function') {
          playPromise.catch(function () {
            previewEndTime = null;
            stopPreviewMonitor();
          });
        }

        startPreviewMonitor();
      });
    }

    function updateTimelineDisplay() {
      const startTime = getStartTime();
      const endTime = getEndTime();
      const startPercentage = videoDuration > 0
        ? (startTime / videoDuration) * 100
        : 0;
      const endPercentage = videoDuration > 0
        ? (endTime / videoDuration) * 100
        : 100;

      trimSelection.style.left = startPercentage + '%';
      trimSelection.style.width = Math.max(0, endPercentage - startPercentage) + '%';

      const formattedStart = formatTimecode(startTime);
      const formattedEnd = formatTimecode(endTime);
      const formattedDuration = formatTimecode(Math.max(0, endTime - startTime));

      startInput.value = formattedStart;
      endInput.value = formattedEnd;

      if (startLabel) {
        startLabel.textContent = formattedStart;
      }

      if (endLabel) {
        endLabel.textContent = formattedEnd;
      }

      if (selectedDurationLabel) {
        selectedDurationLabel.textContent = formattedDuration;
      }

      startHandle.setAttribute('aria-valuetext', formattedStart);
      endHandle.setAttribute('aria-valuetext', formattedEnd);
    }

    function setStartTime(requestedTime, shouldSeekPlayer) {
      const latestAllowedStart = Math.max(0, getEndTime() - minimumClipDuration);
      const newStartTime = clamp(requestedTime, 0, latestAllowedStart);

      startHandle.value = String(newStartTime);
      bringHandleToFront(startHandle);
      updateTimelineDisplay();

      if (shouldSeekPlayer) {
        player.currentTime = newStartTime;
      }
    }

    function setEndTime(requestedTime, shouldSeekPlayer) {
      const earliestAllowedEnd = Math.min(
        videoDuration,
        getStartTime() + minimumClipDuration
      );
      const newEndTime = clamp(requestedTime, earliestAllowedEnd, videoDuration);

      endHandle.value = String(newEndTime);
      bringHandleToFront(endHandle);
      updateTimelineDisplay();

      if (shouldSeekPlayer) {
        player.currentTime = newEndTime;
      }
    }

    startHandle.addEventListener('input', function () {
      setStartTime(Number(startHandle.value), true);
    });

    endHandle.addEventListener('input', function () {
      setEndTime(Number(endHandle.value), true);
    });

    startHandle.addEventListener('pointerdown', function () {
      bringHandleToFront(startHandle);
    });

    startHandle.addEventListener('focus', function () {
      bringHandleToFront(startHandle);
    });

    endHandle.addEventListener('pointerdown', function () {
      bringHandleToFront(endHandle);
    });

    endHandle.addEventListener('focus', function () {
      bringHandleToFront(endHandle);
    });

    trimTrack.addEventListener('click', function (event) {
      const trackBounds = trimTrack.getBoundingClientRect();
      if (trackBounds.width <= 0 || videoDuration <= 0) {
        return;
      }

      const clickPercentage = clamp(
        (event.clientX - trackBounds.left) / trackBounds.width,
        0,
        1
      );
      const clickedTime = clickPercentage * videoDuration;
      const distanceFromStart = Math.abs(clickedTime - getStartTime());
      const distanceFromEnd = Math.abs(clickedTime - getEndTime());

      if (distanceFromStart <= distanceFromEnd) {
        setStartTime(clickedTime, true);
      } else {
        setEndTime(clickedTime, true);
      }
    });

    startInput.addEventListener('change', function () {
      const enteredTime = parseTimecode(startInput.value);
      if (enteredTime === null) {
        updateTimelineDisplay();
        return;
      }

      setStartTime(enteredTime, true);
    });

    endInput.addEventListener('change', function () {
      const enteredTime = parseTimecode(endInput.value);
      if (enteredTime === null) {
        updateTimelineDisplay();
        return;
      }

      setEndTime(enteredTime, true);
    });

    setStartButton?.addEventListener('click', function () {
      setStartTime(player.currentTime, false);
    });

    setEndButton?.addEventListener('click', function () {
      setEndTime(player.currentTime, false);
    });

    document.querySelectorAll('[data-seek-to]').forEach(function (seekButton) {
      seekButton.addEventListener('click', function () {
        const targetInput = seekButton.dataset.seekTo === 'start' ? startInput : endInput;
        const targetTime = parseTimecode(targetInput.value);

        if (targetTime !== null) {
          player.currentTime = targetTime;
        }
      });
    });

    previewButton?.addEventListener('click', function () {
      playTrimmedPreview();
    });

    player.addEventListener('timeupdate', function () {
      if (previewEndTime !== null && player.currentTime >= previewEndTime) {
        finishTrimmedPreview();
      }
    });

    player.addEventListener('pause', function () {
      stopPreviewMonitor();
    });

    player.addEventListener('play', function () {
      if (previewEndTime !== null) {
        startPreviewMonitor();
      }
    });

    player.addEventListener('ended', function () {
      previewEndTime = null;
      stopPreviewMonitor();
    });

    trimForm.addEventListener('submit', function (event) {
      const startTime = parseTimecode(startInput.value);
      const endTime = parseTimecode(endInput.value);

      if (startTime === null || endTime === null || (endTime - startTime) < 0.5) {
        event.preventDefault();
        window.alert('The start and end times are invalid. The trimmed video must be at least 0.5 seconds long.');
      }
    });

    bringHandleToFront(startHandle);
    updateTimelineDisplay();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialiseVodEditor);
  } else {
    initialiseVodEditor();
  }
}());