/**
 * Jitsi Meet TeleHealth Integration for OpenEMR.
 * Uses the Jitsi Meet IFrame API to embed video conferencing.
 *
 * IMPORTANT: OpenEMR uses iframes. The conference room HTML lives in the
 * TOP frame (main.php via RenderEvent::EVENT_BODY_RENDER_POST), while
 * launch buttons live in CHILD iframes (calendar, appointment edit).
 * This script runs in both contexts:
 *   - In child iframes: click handlers delegate to window.top.JitsiTeleHealth.launch()
 *   - In the top frame: manages the Jitsi session and DOM containers
 *
 * @package   openemr
 * @author    EPA Bienestar
 * @copyright Copyright (c) 2024 EPA Bienestar
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

(function (window, document) {
    'use strict';

    // Detect frame context
    var isTopFrame = (window === window.top);
    var isPatientPortal = window.location.pathname.indexOf('/portal/') !== -1;

    // Reference to the top frame's document where conference room HTML lives
    var topWin = isPatientPortal ? window : window.top;
    var topDoc = topWin.document;

    // ========================================================================
    // CHILD IFRAME CONTEXT: only bind click handlers that delegate to top frame
    // ========================================================================
    if (!isTopFrame && !isPatientPortal) {
        function bindChildFrameEvents() {
            document.addEventListener('click', function (e) {
                // Launch button in appointment edit
                var launchBtn = e.target.closest('.btn-jitsi-launch-telehealth');
                if (launchBtn) {
                    e.preventDefault();
                    var eid = launchBtn.getAttribute('data-eid');
                    var pid = launchBtn.getAttribute('data-pid');
                    if (eid && window.top.JitsiTeleHealth) {
                        window.top.JitsiTeleHealth.launch(eid, pid);
                    } else {
                        console.error('JitsiTeleHealth: Top frame module not available');
                    }
                    return;
                }

                // Calendar event click for telehealth
                var telehealthEvent = e.target.closest('.event_jitsi_telehealth.event_telehealth_active');
                if (telehealthEvent) {
                    var eidAttr = telehealthEvent.getAttribute('data-eid');
                    if (eidAttr && window.top.JitsiTeleHealth) {
                        e.preventDefault();
                        window.top.JitsiTeleHealth.launch(eidAttr, null);
                    }
                }
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', bindChildFrameEvents);
        } else {
            bindChildFrameEvents();
        }
        console.log('JitsiTeleHealth: Child frame handler initialized');
        return; // Stop here - the rest only runs in the top frame or patient portal
    }

    // ========================================================================
    // TOP FRAME CONTEXT (or patient portal): full Jitsi session management
    // ========================================================================

    var jitsiApi = null;
    var currentSession = null;
    var heartbeatInterval = null;

    var HEARTBEAT_INTERVAL_MS = 10000; // 10 seconds

    // Discover the module's public path from script tags in the top frame
    var MODULE_PATH = (function () {
        var scripts = topDoc.querySelectorAll('script[src*="jitsi-telehealth"]');
        if (scripts.length > 0) {
            var src = scripts[scripts.length - 1].src;
            return src.substring(0, src.lastIndexOf('/assets/')) + '/';
        }
        // Fallback: look for the settings script
        var settingsScripts = topDoc.querySelectorAll('script[src*="action=get_telehealth_settings"]');
        if (settingsScripts.length > 0) {
            var ssrc = settingsScripts[settingsScripts.length - 1].src;
            return ssrc.substring(0, ssrc.indexOf('index.php')) ;
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
        var url = new URL(getApiPath(), topWin.location.origin);
        url.searchParams.set('action', action);

        Object.keys(params).forEach(function (key) {
            if (params[key] !== null && params[key] !== undefined) {
                url.searchParams.set(key, params[key]);
            }
        });

        var headers = {};
        if (topWin.restoreSession) {
            topWin.restoreSession();
        }

        // Add CSRF token if available
        var csrfToken = topWin.csrfTokenJs || '';
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
     * Load the Jitsi Meet External API script in the top frame.
     */
    function loadJitsiScript(domain) {
        return new Promise(function (resolve, reject) {
            // Check in top frame context
            if (typeof topWin.JitsiMeetExternalAPI !== 'undefined') {
                resolve();
                return;
            }

            var script = topDoc.createElement('script');
            script.src = 'https://' + domain + '/external_api.js';
            script.async = true;
            script.onload = resolve;
            script.onerror = function () {
                reject(new Error('Failed to load Jitsi Meet API from ' + domain));
            };
            topDoc.head.appendChild(script);
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
        // All DOM lookups in the TOP frame document
        var container = topDoc.getElementById('jitsi-telehealth-container');
        var frameContainer = topDoc.getElementById('jitsi-meet-frame');

        if (!container || !frameContainer) {
            console.error('JitsiTeleHealth: Container elements not found in top frame document');
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

        // Create Jitsi Meet instance using top frame's JitsiMeetExternalAPI
        try {
            jitsiApi = new topWin.JitsiMeetExternalAPI(config.jitsiDomain, options);

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
     * Show the conference room container (in top frame).
     */
    function showContainer() {
        var container = topDoc.getElementById('jitsi-telehealth-container');
        var minimized = topDoc.getElementById('jitsi-telehealth-minimized');
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
        var container = topDoc.getElementById('jitsi-telehealth-container');
        var minimized = topDoc.getElementById('jitsi-telehealth-minimized');
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

        // Hide containers (in top frame)
        var container = topDoc.getElementById('jitsi-telehealth-container');
        var minimized = topDoc.getElementById('jitsi-telehealth-minimized');
        if (container) {
            container.classList.add('d-none');
        }
        if (minimized) {
            minimized.classList.add('d-none');
        }

        // Show status update section if provider
        if (showStatusUpdate && currentSession && !isPatientPortal) {
            showHangupStatusUpdate();
        } else {
            currentSession = null;
        }
    }

    /**
     * Show the hangup confirmation modal (in top frame).
     */
    function showHangupConfirm() {
        var modal = topDoc.getElementById('jitsi-hangup-confirm');
        if (modal) {
            var confirmSection = modal.querySelector('.jitsi-hangup-confirm-section');
            var statusSection = modal.querySelector('.jitsi-hangup-status-section');
            if (confirmSection) confirmSection.classList.remove('d-none');
            if (statusSection) statusSection.classList.add('d-none');

            if (typeof topWin.$ !== 'undefined') {
                topWin.$(modal).modal('show');
            } else if (typeof topWin.bootstrap !== 'undefined') {
                new topWin.bootstrap.Modal(modal).show();
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
        var modal = topDoc.getElementById('jitsi-hangup-confirm');
        if (modal) {
            var confirmSection = modal.querySelector('.jitsi-hangup-confirm-section');
            var statusSection = modal.querySelector('.jitsi-hangup-status-section');
            if (confirmSection) confirmSection.classList.add('d-none');
            if (statusSection) statusSection.classList.remove('d-none');

            if (typeof topWin.$ !== 'undefined') {
                topWin.$(modal).modal('show');
            } else if (typeof topWin.bootstrap !== 'undefined') {
                new topWin.bootstrap.Modal(modal).show();
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
            currentSession = null;
            return;
        }

        apiCall('set_appointment_status', {
            pc_eid: currentSession.pc_eid,
            status: status,
            csrf_token: topWin.csrfTokenJs || ''
        }).then(function () {
            closeModal();
            currentSession = null;
        }).catch(function (err) {
            console.error('JitsiTeleHealth: Failed to update status', err);
            closeModal();
            currentSession = null;
        });
    }

    /**
     * Close the modal (in top frame).
     */
    function closeModal() {
        var modal = topDoc.getElementById('jitsi-hangup-confirm');
        if (modal) {
            if (typeof topWin.$ !== 'undefined') {
                topWin.$(modal).modal('hide');
            } else if (typeof topWin.bootstrap !== 'undefined') {
                var bsModal = topWin.bootstrap.Modal.getInstance(modal);
                if (bsModal) bsModal.hide();
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
    // Event Binding (top frame)
    // ========================================

    function bindTopFrameEvents() {
        // Listen for clicks on the top frame document (conference room UI controls)
        topDoc.addEventListener('click', function (e) {
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

            // Patient portal telehealth button (same frame)
            var patientBtn = e.target.closest('.btn-jitsi-patient-telehealth');
            if (patientBtn) {
                e.preventDefault();
                var patientEid = patientBtn.getAttribute('data-eid');
                if (patientEid) {
                    patientLaunchSession(patientEid);
                }
            }

            // Also handle launch button if clicked in top frame directly
            var launchBtn = e.target.closest('.btn-jitsi-launch-telehealth');
            if (launchBtn) {
                e.preventDefault();
                var eid = launchBtn.getAttribute('data-eid');
                var pid = launchBtn.getAttribute('data-pid');
                if (eid) {
                    launchSession(eid, pid);
                }
            }
        });
    }

    // ========================================
    // Initialize (top frame)
    // ========================================

    function init() {
        bindTopFrameEvents();
        console.log('JitsiTeleHealth: Top frame module initialized');
    }

    if (topDoc.readyState === 'loading') {
        topDoc.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Expose public API on the TOP window for child iframes to call
    topWin.JitsiTeleHealth = {
        launch: launchSession,
        end: endSession,
        patientLaunch: patientLaunchSession
    };

})(window, document);
