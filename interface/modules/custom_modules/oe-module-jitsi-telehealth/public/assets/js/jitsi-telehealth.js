/**
 * Jitsi Meet TeleHealth Integration for OpenEMR.
 * Uses the Jitsi Meet IFrame API to embed video conferencing.
 *
 * @package   openemr
 * @author    EPA Bienestar
 * @copyright Copyright (c) 2024 EPA Bienestar
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

(function (window, document) {
    'use strict';

    // Module-level state
    let jitsiApi = null;
    let currentSession = null;
    let heartbeatInterval = null;
    let isPatientPortal = false;

    const HEARTBEAT_INTERVAL_MS = 10000; // 10 seconds
    const MODULE_PATH = (function () {
        const scripts = document.querySelectorAll('script[src*="jitsi-telehealth"]');
        if (scripts.length > 0) {
            const src = scripts[scripts.length - 1].src;
            // Navigate from assets/js/jitsi-telehealth.js to the module public root
            return src.substring(0, src.lastIndexOf('/assets/')) + '/';
        }
        return '';
    })();

    /**
     * Get the API index path based on context (portal vs clinician)
     */
    function getApiPath() {
        if (isPatientPortal) {
            return MODULE_PATH + 'index-portal.php';
        }
        return MODULE_PATH + 'index.php';
    }

    /**
     * Make an API call to the module backend.
     */
    function apiCall(action, params) {
        params = params || {};
        const url = new URL(getApiPath(), window.location.origin);
        url.searchParams.set('action', action);

        Object.keys(params).forEach(function (key) {
            if (params[key] !== null && params[key] !== undefined) {
                url.searchParams.set(key, params[key]);
            }
        });

        const headers = {};
        if (typeof top !== 'undefined' && top.restoreSession) {
            top.restoreSession();
        }

        // Add CSRF token if available
        const csrfToken = typeof csrfTokenJs !== 'undefined' ? csrfTokenJs : '';
        if (csrfToken) {
            headers['apicsrftoken'] = csrfToken;
        }

        return fetch(url.toString(), {
            method: 'GET',
            headers: headers,
            credentials: 'same-origin'
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('API call failed: ' + response.status);
            }
            return response.json();
        });
    }

    /**
     * Load the Jitsi Meet External API script dynamically.
     */
    function loadJitsiScript(domain) {
        return new Promise(function (resolve, reject) {
            if (typeof JitsiMeetExternalAPI !== 'undefined') {
                resolve();
                return;
            }

            const script = document.createElement('script');
            script.src = 'https://' + domain + '/external_api.js';
            script.async = true;
            script.onload = resolve;
            script.onerror = function () {
                reject(new Error('Failed to load Jitsi Meet API from ' + domain));
            };
            document.head.appendChild(script);
        });
    }

    /**
     * Launch a Jitsi TeleHealth session.
     */
    function launchSession(pc_eid, pid) {
        if (jitsiApi) {
            console.warn('JitsiTeleHealth: Session already active');
            showContainer();
            return;
        }

        apiCall('get_telehealth_launch_data', { pc_eid: pc_eid, pid: pid })
            .then(function (data) {
                if (data.error) {
                    alert('Error: ' + data.error);
                    return;
                }
                currentSession = data;
                return loadJitsiScript(data.jitsiDomain).then(function () {
                    startJitsiSession(data);
                });
            })
            .catch(function (err) {
                console.error('JitsiTeleHealth: Failed to launch session', err);
                alert('Failed to launch telehealth session. Please try again.');
            });
    }

    /**
     * Start the Jitsi Meet session using the IFrame API.
     */
    function startJitsiSession(config) {
        const container = document.getElementById('jitsi-telehealth-container');
        const frameContainer = document.getElementById('jitsi-meet-frame');

        if (!container || !frameContainer) {
            console.error('JitsiTeleHealth: Container elements not found');
            return;
        }

        // Clear previous content
        frameContainer.innerHTML = '';

        // Build toolbar buttons
        var toolbarButtons = [
            'microphone', 'camera', 'closedcaptions', 'desktop',
            'fullscreen', 'fodeviceselection', 'hangup', 'profile',
            'raisehand', 'videoquality', 'filmstrip', 'feedback',
            'stats', 'shortcuts', 'tileview', 'select-background',
            'mute-everyone', 'mute-video-everyone', 'security'
        ];

        if (config.enableChat) {
            toolbarButtons.push('chat');
        }
        if (config.enableRecording) {
            toolbarButtons.push('recording');
        }
        if (!config.enableScreenSharing) {
            var idx = toolbarButtons.indexOf('desktop');
            if (idx > -1) {
                toolbarButtons.splice(idx, 1);
            }
        }

        // Jitsi Meet options
        var options = {
            roomName: config.roomName,
            parentNode: frameContainer,
            width: '100%',
            height: '100%',
            configOverwrite: {
                startWithAudioMuted: false,
                startWithVideoMuted: false,
                disableDeepLinking: true,
                prejoinPageEnabled: false,
                enableLobbyChat: config.enableChat,
                defaultLanguage: config.defaultLanguage || 'es',
                disableModeratorIndicator: false,
                enableEmailInStats: false,
                requireDisplayName: config.requireDisplayName,
                disableRemoteMute: !config.isModerator,
                remoteVideoMenu: {
                    disableKick: !config.isModerator,
                    disableGrantModerator: !config.isModerator
                },
                lobby: {
                    autoKnock: true,
                    enableChat: config.enableChat
                },
                notifications: [],
                toolbarButtons: toolbarButtons
            },
            interfaceConfigOverwrite: {
                SHOW_JITSI_WATERMARK: false,
                SHOW_WATERMARK_FOR_GUESTS: false,
                SHOW_BRAND_WATERMARK: false,
                SHOW_POWERED_BY: false,
                DEFAULT_BACKGROUND: '#1a1a2e',
                TOOLBAR_ALWAYS_VISIBLE: true,
                DISABLE_JOIN_LEAVE_NOTIFICATIONS: false,
                MOBILE_APP_PROMO: false,
                HIDE_INVITE_MORE_HEADER: true,
                DISABLE_FOCUS_INDICATOR: false
            },
            userInfo: {
                displayName: config.displayName,
                email: config.email
            }
        };

        // Add JWT if provided
        if (config.jwt) {
            options.jwt = config.jwt;
        }

        // Update patient name display
        var patientNameEl = container.querySelector('.jitsi-telehealth-patient-name');
        if (patientNameEl && config.displayName) {
            patientNameEl.textContent = '- ' + config.displayName;
        }

        // Create Jitsi Meet instance
        try {
            jitsiApi = new JitsiMeetExternalAPI(config.jitsiDomain, options);

            // Event handlers
            jitsiApi.addListener('readyToClose', function () {
                endSession(false);
            });

            jitsiApi.addListener('videoConferenceJoined', function () {
                console.log('JitsiTeleHealth: Conference joined');
                startHeartbeat();

                // Enable lobby if moderator and configured
                if (config.isModerator && config.enableLobby) {
                    jitsiApi.executeCommand('toggleLobby', true);
                }
            });

            jitsiApi.addListener('videoConferenceLeft', function () {
                console.log('JitsiTeleHealth: Conference left');
                stopHeartbeat();
            });

            jitsiApi.addListener('participantJoined', function (participant) {
                console.log('JitsiTeleHealth: Participant joined', participant);
            });

            // Show container
            showContainer();
        } catch (e) {
            console.error('JitsiTeleHealth: Failed to create Jitsi instance', e);
            alert('Failed to start video session. Please check your connection and try again.');
        }
    }

    /**
     * Show the conference room container.
     */
    function showContainer() {
        var container = document.getElementById('jitsi-telehealth-container');
        var minimized = document.getElementById('jitsi-telehealth-minimized');
        if (container) {
            container.classList.remove('d-none');
        }
        if (minimized) {
            minimized.classList.add('d-none');
        }
    }

    /**
     * Minimize the conference room.
     */
    function minimizeSession() {
        var container = document.getElementById('jitsi-telehealth-container');
        var minimized = document.getElementById('jitsi-telehealth-minimized');
        if (container) {
            container.classList.add('d-none');
        }
        if (minimized) {
            minimized.classList.remove('d-none');
        }
    }

    /**
     * Maximize the conference room.
     */
    function maximizeSession() {
        showContainer();
    }

    /**
     * Start the heartbeat to keep session alive.
     */
    function startHeartbeat() {
        stopHeartbeat();
        heartbeatInterval = setInterval(function () {
            if (currentSession && currentSession.pc_eid) {
                apiCall('conference_session_update', {
                    pc_eid: currentSession.pc_eid
                }).catch(function (err) {
                    console.warn('JitsiTeleHealth: Heartbeat failed', err);
                });
            }
        }, HEARTBEAT_INTERVAL_MS);
    }

    /**
     * Stop the heartbeat.
     */
    function stopHeartbeat() {
        if (heartbeatInterval) {
            clearInterval(heartbeatInterval);
            heartbeatInterval = null;
        }
    }

    /**
     * End the telehealth session.
     */
    function endSession(showStatusUpdate) {
        stopHeartbeat();

        if (jitsiApi) {
            try {
                jitsiApi.dispose();
            } catch (e) {
                console.warn('JitsiTeleHealth: Error disposing Jitsi API', e);
            }
            jitsiApi = null;
        }

        // Hide containers
        var container = document.getElementById('jitsi-telehealth-container');
        var minimized = document.getElementById('jitsi-telehealth-minimized');
        if (container) {
            container.classList.add('d-none');
        }
        if (minimized) {
            minimized.classList.add('d-none');
        }

        // Show status update section if provider
        if (showStatusUpdate && currentSession && !isPatientPortal) {
            showHangupStatusUpdate();
        }

        currentSession = null;
    }

    /**
     * Show the hangup confirmation modal.
     */
    function showHangupConfirm() {
        var modal = document.getElementById('jitsi-hangup-confirm');
        if (modal) {
            var confirmSection = modal.querySelector('.jitsi-hangup-confirm-section');
            var statusSection = modal.querySelector('.jitsi-hangup-status-section');
            if (confirmSection) confirmSection.classList.remove('d-none');
            if (statusSection) statusSection.classList.add('d-none');

            if (typeof $ !== 'undefined') {
                $(modal).modal('show');
            } else {
                modal.style.display = 'block';
                modal.classList.add('show');
            }
        }
    }

    /**
     * Show the appointment status update section.
     */
    function showHangupStatusUpdate() {
        var modal = document.getElementById('jitsi-hangup-confirm');
        if (modal) {
            var confirmSection = modal.querySelector('.jitsi-hangup-confirm-section');
            var statusSection = modal.querySelector('.jitsi-hangup-status-section');
            if (confirmSection) confirmSection.classList.add('d-none');
            if (statusSection) statusSection.classList.remove('d-none');

            if (typeof $ !== 'undefined') {
                $(modal).modal('show');
            } else {
                modal.style.display = 'block';
                modal.classList.add('show');
            }
        }
    }

    /**
     * Set appointment status after session end.
     */
    function setAppointmentStatus(status) {
        if (!currentSession || status === 'CloseWithoutUpdating') {
            closeModal();
            return;
        }

        apiCall('set_appointment_status', {
            pc_eid: currentSession.pc_eid,
            status: status,
            csrf_token: typeof csrfTokenJs !== 'undefined' ? csrfTokenJs : ''
        }).then(function () {
            closeModal();
        }).catch(function (err) {
            console.error('JitsiTeleHealth: Failed to update status', err);
            closeModal();
        });
    }

    /**
     * Close the modal.
     */
    function closeModal() {
        var modal = document.getElementById('jitsi-hangup-confirm');
        if (modal) {
            if (typeof $ !== 'undefined') {
                $(modal).modal('hide');
            } else {
                modal.style.display = 'none';
                modal.classList.remove('show');
            }
        }
    }

    /**
     * Patient portal: check if provider is ready and launch.
     */
    function patientLaunchSession(pc_eid) {
        isPatientPortal = true;
        apiCall('patient_appointment_ready', { pc_eid: pc_eid })
            .then(function (data) {
                if (data.providerReady) {
                    launchSession(pc_eid, null);
                } else {
                    alert('Your provider has not started the session yet. Please wait and try again.');
                }
            })
            .catch(function (err) {
                console.error('JitsiTeleHealth: Failed to check provider status', err);
                // Launch anyway - provider may join shortly
                launchSession(pc_eid, null);
            });
    }

    // ========================================
    // Event Binding
    // ========================================

    function bindEvents() {
        // Provider: Launch button in appointment edit
        document.addEventListener('click', function (e) {
            var launchBtn = e.target.closest('.btn-jitsi-launch-telehealth');
            if (launchBtn) {
                e.preventDefault();
                var eid = launchBtn.getAttribute('data-eid');
                var pid = launchBtn.getAttribute('data-pid');
                if (eid) {
                    launchSession(eid, pid);
                }
            }

            // Calendar event click for telehealth
            var telehealthEvent = e.target.closest('.event_jitsi_telehealth.event_telehealth_active');
            if (telehealthEvent) {
                var eidAttr = telehealthEvent.getAttribute('data-eid');
                if (eidAttr) {
                    e.preventDefault();
                    launchSession(eidAttr, null);
                }
            }

            // Minimize button
            if (e.target.closest('.jitsi-btn-minimize')) {
                e.preventDefault();
                minimizeSession();
            }

            // Maximize button
            if (e.target.closest('.jitsi-btn-maximize')) {
                e.preventDefault();
                maximizeSession();
            }

            // Hangup buttons
            if (e.target.closest('.jitsi-btn-hangup') || e.target.closest('.jitsi-btn-hangup-mini')) {
                e.preventDefault();
                if (isPatientPortal) {
                    endSession(false);
                } else {
                    showHangupConfirm();
                }
            }

            // Confirm hangup
            if (e.target.closest('.jitsi-btn-confirm-hangup')) {
                e.preventDefault();
                endSession(true);
            }

            // Set appointment status
            var statusBtn = e.target.closest('.jitsi-btn-set-status');
            if (statusBtn) {
                e.preventDefault();
                setAppointmentStatus(statusBtn.getAttribute('data-status'));
            }

            // Patient portal telehealth button
            var patientBtn = e.target.closest('.btn-jitsi-patient-telehealth');
            if (patientBtn) {
                e.preventDefault();
                isPatientPortal = true;
                var patientEid = patientBtn.getAttribute('data-eid');
                if (patientEid) {
                    patientLaunchSession(patientEid);
                }
            }
        });
    }

    // ========================================
    // Initialize
    // ========================================

    function init() {
        // Detect if we're in the patient portal
        isPatientPortal = window.location.pathname.indexOf('/portal/') !== -1;
        bindEvents();
        console.log('JitsiTeleHealth: Module initialized');
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Expose public API for external use
    window.JitsiTeleHealth = {
        launch: launchSession,
        end: endSession,
        patientLaunch: patientLaunchSession
    };

})(window, document);
