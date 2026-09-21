/**
 * Dreamax Affiliates frontend JS: registration AJAX and affiliate-link generator.
 */
( function ( $ ) {
	'use strict';

	function getLiveRegion() {
		var $region = $( '#affilio-global-live-region' );
		if ( ! $region.length ) {
			$region = $( '<div>' )
				.attr( {
					id: 'affilio-global-live-region',
					role: 'status',
					'aria-live': 'polite',
					'aria-atomic': 'true'
				} )
				.addClass( 'affilio-sr-only' )
				.appendTo( 'body' );
		}
		return $region;
	}

	function announce( message, assertive ) {
		var $region = getLiveRegion();
		$region.attr( 'aria-live', assertive ? 'assertive' : 'polite' ).text( '' );
		window.setTimeout( function () { $region.text( message ); }, 20 );
	}

	function initRegistration() {
		$( '.affilio-registration-form' ).each( function () {
			var $form = $( this );
			var $message = $form.find( '.affilio-form-message' );
			var $button = $form.find( 'button[type="submit"]' );
			var $steps = $form.find( '[data-affilio-registration-step]' );
			var $stepButtons = $form.find( '[data-affilio-step-target]' );
			var $final = $form.find( '[data-affilio-registration-final]' );
			var $progress = $form.find( '.affilio-registration-progress-bar' );
			var $currentStep = $form.find( '[data-affilio-current-step]' );
			var currentStep = 1;
			var furthestStep = 1;
			var totalSteps = $steps.length;
			var isSubmitting = false;
			var isComplete = false;
			var $navigation = $form.find( '[data-affilio-step-next], [data-affilio-step-back], [data-affilio-step-target]' );
			var defaultButtonLabel = String( $button.attr( 'data-default-label' ) || $button.text() );

			function getStep( stepNumber ) {
				return $steps.filter( function () {
					return Number( $( this ).attr( 'data-affilio-registration-step' ) ) === stepNumber;
				} ).first();
			}

			function firstInvalidField( $scope ) {
				return $scope.find( ':input' ).filter( function () {
					return typeof this.checkValidity === 'function' && ! this.checkValidity();
				} ).first();
			}

			function showStep( stepNumber, options ) {
				var progressPercent;
				var $activeStep;

				options = options || {};
				stepNumber = Math.max( 1, Math.min( totalSteps, Number( stepNumber ) || 1 ) );
				currentStep = stepNumber;
				$activeStep = getStep( currentStep );

				$steps.each( function () {
					var $step = $( this );
					var active = Number( $step.attr( 'data-affilio-registration-step' ) ) === currentStep;
					$step.prop( 'hidden', ! active ).attr( 'aria-hidden', active ? 'false' : 'true' );
				} );

				$final.prop( 'hidden', currentStep !== totalSteps );
				$currentStep.text( currentStep );
				progressPercent = totalSteps ? ( currentStep / totalSteps ) * 100 : 100;
				$progress.attr( 'aria-valuenow', currentStep ).find( '> span' ).css( 'width', progressPercent + '%' );

				$stepButtons.each( function () {
					var $stepButton = $( this );
					var targetStep = Number( $stepButton.attr( 'data-affilio-step-target' ) );
					var isCurrent = targetStep === currentStep;
					$stepButton.prop( 'disabled', targetStep > furthestStep );
					$stepButton.closest( 'li' ).toggleClass( 'is-complete', targetStep < currentStep );
					if ( isCurrent ) {
						$stepButton.attr( 'aria-current', 'step' );
					} else {
						$stepButton.removeAttr( 'aria-current' );
					}
				} );

				if ( options.focus !== false && $activeStep.length ) {
					$activeStep.find( 'h3' ).first().trigger( 'focus' );
				}

				if ( options.announce !== false ) {
					announce( $activeStep.find( 'h3' ).first().text(), false );
				}
			}

			function validateStep( stepNumber ) {
				var $invalid = firstInvalidField( getStep( stepNumber ) );

				if ( ! $invalid.length ) {
					return true;
				}

				if ( typeof $invalid.get( 0 ).reportValidity === 'function' ) {
					$invalid.get( 0 ).reportValidity();
				} else {
					$invalid.trigger( 'focus' );
				}
				return false;
			}

			function clearFieldError( $field ) {
				var describedBy = String( $field.attr( 'aria-describedby' ) || '' )
					.split( /\s+/ )
					.filter( function ( id ) { return id && id !== $message.attr( 'id' ); } )
					.join( ' ' );

				$field.removeAttr( 'aria-invalid' );
				if ( describedBy ) {
					$field.attr( 'aria-describedby', describedBy );
				} else {
					$field.removeAttr( 'aria-describedby' );
				}
			}

			function clearFieldErrors() {
				$form.find( '[aria-invalid="true"]' ).each( function () {
					clearFieldError( $( this ) );
				} );
			}

			function appendActions( $container, actions ) {
				if ( ! Array.isArray( actions ) || ! actions.length ) {
					return;
				}

				var $actions = $( '<div>' ).addClass( 'affilio-registration-message-actions' );
				actions.forEach( function ( action ) {
					if ( ! action || ! action.url || ! action.label ) {
						return;
					}

					var style = action.style === 'primary' ? 'primary' : 'secondary';
					$actions.append(
						$( '<a>' )
							.addClass( 'affilio-registration-message-action is-' + style )
							.attr( 'href', action.url )
							.text( action.label )
					);
				} );

				if ( $actions.children().length ) {
					$container.append( $actions );
				}
			}

			function showError( data ) {
				var errorMessage = data && data.message ? data.message : affilioFrontend.genericError;
				var fieldName = data && data.field ? String( data.field ) : '';
				var $field = fieldName ? $form.find( '[name]' ).filter( function () { return this.name === fieldName; } ).first() : $();

				$message
					.attr( { role: 'alert', 'aria-live': 'assertive' } )
					.removeClass( 'affilio-success' )
					.addClass( 'affilio-error' )
					.empty()
					.append( $( '<span>' ).text( errorMessage ) )
					.prop( 'hidden', false );
				appendActions( $message, data && data.actions ? data.actions : [] );

				if ( $field.length ) {
					var $ownerStep = $field.closest( '[data-affilio-registration-step]' );
					var ids = String( $field.attr( 'aria-describedby' ) || '' ).split( /\s+/ ).filter( Boolean );
					if ( $ownerStep.length ) {
						showStep( Number( $ownerStep.attr( 'data-affilio-registration-step' ) ), { focus: false, announce: false } );
					}
					if ( $message.attr( 'id' ) && ids.indexOf( $message.attr( 'id' ) ) === -1 ) {
						ids.push( $message.attr( 'id' ) );
					}
					$field.attr( { 'aria-invalid': 'true', 'aria-describedby': ids.join( ' ' ) } ).trigger( 'focus' );
				} else {
					$message.trigger( 'focus' );
				}
			}

			var $hostMain = $form.closest( 'main' ).first();
			if ( $hostMain.length ) {
				$hostMain.addClass( 'affilio-registration-host-main' );
			}

			$form.addClass( 'is-wizard-enhanced' );
			showStep( 1, { focus: false, announce: false } );

			$form.on( 'click', '[data-affilio-step-next]', function () {
				if ( isSubmitting || isComplete ) {
					return;
				}
				if ( ! validateStep( currentStep ) ) {
					return;
				}
				furthestStep = Math.max( furthestStep, Math.min( totalSteps, currentStep + 1 ) );
				showStep( currentStep + 1 );
			} );

			$form.on( 'click', '[data-affilio-step-back]', function () {
				if ( isSubmitting || isComplete ) {
					return;
				}
				showStep( currentStep - 1 );
			} );

			$form.on( 'click', '[data-affilio-step-target]', function () {
				if ( isSubmitting || isComplete ) {
					return;
				}
				var targetStep = Number( $( this ).attr( 'data-affilio-step-target' ) );
				if ( targetStep > currentStep && ! validateStep( currentStep ) ) {
					return;
				}
				showStep( targetStep );
			} );

			$form.on( 'input change', '[aria-invalid="true"]', function () {
				clearFieldError( $( this ) );
			} );

			$form.on( 'submit', function ( e ) {
				var $invalid;
				var $invalidStep;
				e.preventDefault();
				if ( isSubmitting || isComplete ) {
					return;
				}
				clearFieldErrors();
				if ( $form.get( 0 ) && ! $form.get( 0 ).checkValidity() ) {
					$invalid = firstInvalidField( $form );
					$invalidStep = $invalid.closest( '[data-affilio-registration-step]' );
					if ( $invalidStep.length ) {
						showStep( Number( $invalidStep.attr( 'data-affilio-registration-step' ) ), { focus: false, announce: false } );
					}
					if ( $invalid.length && typeof $invalid.get( 0 ).reportValidity === 'function' ) {
						$invalid.get( 0 ).reportValidity();
					} else {
						$form.get( 0 ).reportValidity();
					}
					return;
				}

				isSubmitting = true;
				$navigation.each( function () { $( this ).data( 'affilio-was-disabled', this.disabled ).prop( 'disabled', true ); } );
				$button.prop( 'disabled', true ).text( affilioFrontend.submitLabel );
				$form.attr( 'aria-busy', 'true' );
				$message.attr( { role: 'status', 'aria-live': 'polite' } ).prop( 'hidden', true );
				announce( affilioFrontend.formSubmitting, false );

				$.post( affilioFrontend.ajaxUrl, $form.serialize() + '&action=affilio_register' )
					.done( function ( response ) {
						if ( response && response.success ) {
							var data = response.data || {};
							var $card = $( '<div>' ).addClass( 'affilio-registration-success-card' );
							var $content = $( '<div>' ).addClass( 'affilio-registration-success-content' );

							$card.append( $( '<span>' ).addClass( 'affilio-registration-success-icon' ).attr( 'aria-hidden', 'true' ).text( '✓' ) );
							if ( data.title ) {
								$content.append( $( '<h3>' ).text( data.title ) );
							}
							$content.append( $( '<p>' ).addClass( 'affilio-registration-success-summary' ).text( data.message || '' ) );

							if ( data.account ) {
								var $account = $( '<div>' ).addClass( 'affilio-registration-account-access' );
								if ( data.account.heading ) {
									$account.append( $( '<h4>' ).text( data.account.heading ) );
								}
								if ( data.account.guidance ) {
									$account.append( $( '<p>' ).text( data.account.guidance ) );
								}
								if ( data.account.email ) {
									var $details = $( '<dl>' );
									$details.append( $( '<dt>' ).text( data.account.emailLabel || '' ) );
									$details.append( $( '<dd>' ).text( data.account.email ) );
									$account.append( $details );
								}
								if ( data.account.session ) {
									$account.append( $( '<p>' ).addClass( 'affilio-registration-session-note' ).text( data.account.session ) );
								}
								$content.append( $account );
							}

							appendActions( $content, data.actions || [] );
							$card.append( $content );
							$message
								.attr( { role: 'status', 'aria-live': 'polite' } )
								.removeClass( 'affilio-error' )
								.addClass( 'affilio-success' )
								.empty()
								.append( $card )
								.prop( 'hidden', false );

							isComplete = true;
							$form.addClass( 'is-registration-complete' );
							$steps.add( $final ).prop( 'hidden', true ).attr( 'aria-hidden', 'true' );
							$form.find( ':input' ).prop( 'disabled', true );
							$stepButtons.removeAttr( 'aria-current' ).closest( 'li' ).addClass( 'is-complete' );
							$message.trigger( 'focus' );
						} else {
							showError( response && response.data ? response.data : null );
						}
					} )
					.fail( function ( xhr ) {
						showError( xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : null );
					} )
					.always( function () {
						isSubmitting = false;
						$button.prop( 'disabled', isComplete ).text( defaultButtonLabel );
						$navigation.each( function () {
							$( this ).prop( 'disabled', isComplete || Boolean( $( this ).data( 'affilio-was-disabled' ) ) );
						} );
						$form.removeAttr( 'aria-busy' );
					} );
			} );
		} );
	}

	function copyText( value, $source ) {
		var copiedMessage = $source.hasClass( 'affilio-copy-value' ) ? affilioFrontend.copyCodeMessage : affilioFrontend.copiedMessage;
		function success() {
			var original = $source.data( 'original-label' );
			if ( ! original ) {
				original = $source.text();
				$source.data( 'original-label', original );
			}
			$source.text( copiedMessage );
			announce( copiedMessage, false );
			window.setTimeout( function () { $source.text( original ); }, 1600 );
		}

		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( value ).then( success, function () { announce( affilioFrontend.copyFailed, true ); window.prompt( affilioFrontend.copyFailed, value ); } );
			return;
		}

		var $temp = $( '<textarea>' ).val( value ).attr( 'readonly', true ).css( { position: 'absolute', left: '-9999px' } ).appendTo( 'body' );
		$temp.trigger( 'select' );
		try {
			if ( document.execCommand( 'copy' ) ) {
				success();
			} else {
				announce( affilioFrontend.copyFailed, true );
				window.prompt( affilioFrontend.copyFailed, value );
			}
		} catch ( error ) {
			announce( affilioFrontend.copyFailed, true ); window.prompt( affilioFrontend.copyFailed, value );
		}
		$temp.remove();
	}

	function initCopyValues() {
		$( document ).on( 'click', '.affilio-copy-value', function () {
			var $button = $( this );
			copyText( String( $button.attr( 'data-copy-value' ) || '' ), $button );
		} );
	}

	function openShareWindow( url ) {
		var popup = window.open( url, '_blank', 'noopener,noreferrer,width=720,height=620' );
		if ( popup ) {
			popup.opener = null;
		}
	}

	function initLinkGenerator() {
		$( '.affilio-link-generator' ).each( function () {
			var $generator = $( this );
			var $destination = $generator.find( '.affilio-link-destination' );
			var $campaign = $generator.find( '.affilio-link-campaign' );
			var $output = $generator.find( '.affilio-referral-link' );
			var $status = $generator.find( '.affilio-copy-status' );
			var $qrPanel = $generator.find( '.affilio-qr-panel' );
			var $qrOutput = $generator.find( '.affilio-qr-output' );
			var $qrStatus = $generator.find( '.affilio-qr-status' );
			var $linkDependentActions = $generator.find( '.affilio-copy-link, [data-affilio-share], .affilio-show-qr, .affilio-download-qr' );
			var homeUrl = String( $generator.data( 'home-url' ) || '' );
			var referralCode = String( $generator.data( 'referral-code' ) || '' );

			function renderQrCode( value ) {
				$qrPanel.attr( 'aria-busy', 'true' );
				if ( ! window.AffilioQRCode || ! $qrOutput.length ) {
					$qrStatus.text( affilioFrontend.qrError );
					$qrPanel.removeAttr( 'aria-busy' );
					return false;
				}
				try {
					window.AffilioQRCode.render( $qrOutput.get( 0 ), value, { quietZone: 4 } );
					$qrStatus.text( affilioFrontend.qrCreated );
					$qrPanel.removeAttr( 'aria-busy' );
					return true;
				} catch ( error ) {
					$qrOutput.empty();
					$qrStatus.text( affilioFrontend.qrError );
					$qrPanel.removeAttr( 'aria-busy' );
					return false;
				}
			}

			function setLinkActionsEnabled( enabled ) {
				$linkDependentActions.prop( 'disabled', ! enabled );

				if ( enabled ) {
					$linkDependentActions.removeAttr( 'aria-disabled' );
					return;
				}

				$linkDependentActions.attr( 'aria-disabled', 'true' );
			}

			function invalidateGeneratedLink() {
				$output.val( '' );
				$destination.attr( 'aria-invalid', 'true' );
				$status.addClass( 'affilio-copy-status-error' ).text( affilioFrontend.invalidUrl );
				setLinkActionsEnabled( false );

				$qrPanel.prop( 'hidden', true ).removeAttr( 'aria-busy' );
				$generator.find( '.affilio-show-qr' ).attr( 'aria-expanded', 'false' );
				$qrOutput.empty();
				$qrStatus.text( '' );
			}

			function validateGeneratedLink() {
				$destination.removeAttr( 'aria-invalid' );
				$status.removeClass( 'affilio-copy-status-error' ).text( '' );
				setLinkActionsEnabled( true );
			}

			function generateLink() {
				var target;
				var home;

				try {
					home = new URL( homeUrl );
					target = new URL( $destination.val() || homeUrl, homeUrl );
				} catch ( error ) {
					invalidateGeneratedLink();
					return false;
				}

				if ( target.host !== home.host || ( target.protocol !== 'http:' && target.protocol !== 'https:' ) ) {
					invalidateGeneratedLink();
					return false;
				}

				target.searchParams.set( 'ref', referralCode );
				target.searchParams.delete( 'aff_campaign' );

				if ( $campaign.val().trim() ) {
					target.searchParams.set( 'aff_campaign', $campaign.val().trim().substring( 0, 100 ) );
				}

				$output.val( target.toString() );
				validateGeneratedLink();
				if ( ! $qrPanel.prop( 'hidden' ) ) {
					renderQrCode( target.toString() );
				}
				return true;
			}

			setLinkActionsEnabled( Boolean( String( $output.val() || '' ).trim() ) );

			$generator.on( 'click', '.affilio-generate-link', generateLink );
			$generator.on( 'input change', '.affilio-link-destination, .affilio-link-campaign', function () {
				if ( String( $output.val() || '' ).trim() ) {
					generateLink();
				}
			} );

			$generator.on( 'click', '.affilio-copy-link', function () {
				if ( ! generateLink() ) {
					return;
				}
				copyText( $output.val(), $( this ) );
			} );

			if ( ! navigator.share ) {
				$generator.find( '.affilio-native-share' ).prop( 'hidden', true );
			}

			$generator.on( 'click', '[data-affilio-share]', function () {
				if ( ! generateLink() ) {
					return;
				}
				var network = String( $( this ).attr( 'data-affilio-share' ) || '' );
				var link = $output.val();
				var encodedLink = encodeURIComponent( link );
				var encodedText = encodeURIComponent( affilioFrontend.shareText );
				var encodedTitle = encodeURIComponent( affilioFrontend.shareTitle );

				if ( network === 'native' && navigator.share ) {
					navigator.share( { title: affilioFrontend.shareTitle, text: affilioFrontend.shareText, url: link } ).catch( function ( error ) {
						if ( error && error.name !== 'AbortError' ) {
							$status.text( affilioFrontend.shareUnavailable );
						}
					} );
					return;
				}
				if ( network === 'facebook' ) {
					openShareWindow( 'https://www.facebook.com/sharer/sharer.php?u=' + encodedLink );
				} else if ( network === 'linkedin' ) {
					openShareWindow( 'https://www.linkedin.com/sharing/share-offsite/?url=' + encodedLink );
				} else if ( network === 'x' ) {
					openShareWindow( 'https://twitter.com/intent/tweet?text=' + encodedText + '&url=' + encodedLink );
				} else if ( network === 'whatsapp' ) {
					openShareWindow( 'https://wa.me/?text=' + encodeURIComponent( affilioFrontend.shareText + ' ' + link ) );
				} else if ( network === 'email' ) {
					window.location.href = 'mailto:?subject=' + encodedTitle + '&body=' + encodeURIComponent( affilioFrontend.shareText + '\n\n' + link );
				} else {
					$status.text( affilioFrontend.shareUnavailable );
				}
			} );

			$generator.on( 'click', '.affilio-show-qr', function () {
				if ( ! generateLink() ) {
					return;
				}
				var $button = $( this );
				var willOpen = $qrPanel.prop( 'hidden' );
				$qrPanel.prop( 'hidden', ! willOpen );
				$button.attr( 'aria-expanded', willOpen ? 'true' : 'false' );
				if ( willOpen ) {
					renderQrCode( $output.val() );
					$qrPanel.trigger( 'focus' );
					announce( affilioFrontend.qrOpen, false );
				} else {
					announce( affilioFrontend.qrClosed, false );
				}
			} );

			$qrPanel.on( 'keydown', function ( event ) {
				if ( event.key === 'Escape' ) {
					$qrPanel.prop( 'hidden', true );
					$generator.find( '.affilio-show-qr' ).attr( 'aria-expanded', 'false' ).trigger( 'focus' );
					announce( affilioFrontend.qrClosed, false );
				}
			} );

			$generator.on( 'click', '.affilio-download-qr', function () {
				if ( ! generateLink() || ! window.AffilioQRCode ) {
					$qrStatus.text( affilioFrontend.qrError );
					return;
				}
				try {
					window.AffilioQRCode.downloadSvg( $output.val(), affilioFrontend.qrFilename, { quietZone: 4 } );
				} catch ( error ) {
					$qrStatus.text( affilioFrontend.qrError );
				}
			} );
		} );
	}



	function initPayoutProfiles() {
		$( '.affilio-profile-settings-form' ).each( function () {
			var $form = $( this );
			var $method = $form.find( '.affilio-payout-method' );
			var $details = $form.find( '.affilio-payout-details' );
			function updateRequirement() {
				var required = String( $method.val() || 'paypal' ) !== 'paypal';
				$details.prop( 'required', required ).attr( 'aria-required', required ? 'true' : 'false' );
				$form.find( '.affilio-payout-details-required' ).prop( 'hidden', ! required );
			}
			$method.on( 'change', updateRequirement );
			updateRequirement();
		} );
	}
	function initAffiliatePortal() {
		$( '[data-affilio-portal]' ).each( function () {
			var $portal = $( this );
			var $hostMain = $portal.closest( 'main' ).first();
			var $nav = $portal.find( '[data-affilio-portal-nav]' ).first();

			if ( $hostMain.length ) {
				$hostMain.addClass( 'affilio-portal-host-main' );
			}

			if ( ! $nav.length ) {
				return;
			}

			/*
			 * Keep the sidebar independent from the content flow. The previous
			 * grid-row span approach generated a very tall implicit grid, leaving
			 * a large blank scroll area after shorter tabs.
			 */
			var $workspace = $portal.children( '[data-affilio-portal-workspace]' ).first();
			if ( ! $workspace.length ) {
				$workspace = $( '<div class="affilio-portal-workspace" data-affilio-portal-workspace></div>' );
				$portal.children().not( $nav ).appendTo( $workspace );
				$nav.after( $workspace );
			}

			var $navLinks = $nav.find( '[data-affilio-panel-target]' );
			var $panels = $workspace.find( '[data-affilio-panel]' );
			var $panelNotices = $workspace.find( '[data-affilio-notice-panel]' );

			if ( ! $navLinks.length || ! $panels.length ) {
				return;
			}

			function panelExists( panelName ) {
				return $panels.filter( function () {
					return String( $( this ).attr( 'data-affilio-panel' ) || '' ) === panelName;
				} ).length > 0;
			}

			function targetFromHash( hash ) {
				try {
					var target = document.getElementById( decodeURIComponent( hash.slice( 1 ) ) );
					return target && $.contains( $portal.get( 0 ), target ) ? $( target ) : $();
				} catch ( error ) {
					return $();
				}
			}

			function panelFromHash() {
				var hash = String( window.location.hash || '' );

				if ( hash.indexOf( '#affiliate-' ) === 0 ) {
					var stable = hash.replace( '#affiliate-', '' );
					if ( panelExists( stable ) ) {
						return stable;
					}
				}

				if ( hash ) {
					var $target = targetFromHash( hash );
					if ( $target.length ) {
						var direct = String( $target.attr( 'data-affilio-panel' ) || '' );
						if ( direct && panelExists( direct ) ) {
							return direct;
						}
						var $owner = $target.closest( '[data-affilio-panel]' );
						if ( $owner.length ) {
							return String( $owner.attr( 'data-affilio-panel' ) || 'overview' );
						}
					}
				}

				return '';
			}

			function initialPanel() {
				var hashPanel = panelFromHash();
				if ( hashPanel ) {
					return hashPanel;
				}

				var params = new URLSearchParams( window.location.search || '' );
				var requested = String( params.get( 'affilio_panel' ) || '' );
				if ( requested && panelExists( requested ) ) {
					return requested;
				}

				if ( params.has( 'affilio_profile_updated' ) || params.has( 'affilio_profile_error' ) ) {
					return panelExists( 'profile' ) ? 'profile' : 'overview';
				}

				if (
					params.has( 'affilio_payout_updated' ) ||
					params.has( 'affilio_payout_error' ) ||
					params.has( 'affilio_request_updated' ) ||
					params.has( 'affilio_request_error' )
				) {
					return panelExists( 'payouts' ) ? 'payouts' : 'overview';
				}

				if ( params.has( 'affilio_date_from' ) || params.has( 'affilio_date_to' ) ) {
					return panelExists( 'results' ) ? 'results' : 'overview';
				}

				return 'overview';
			}

			function updateStableHash( panelName, pushState ) {
				if ( ! window.history || ! window.history.replaceState ) {
					return;
				}

				var url = new URL( window.location.href );
				url.hash = 'affiliate-' + panelName;

				if ( pushState && window.history.pushState ) {
					window.history.pushState( { affilioPanel: panelName }, '', url.toString() );
				} else {
					window.history.replaceState( { affilioPanel: panelName }, '', url.toString() );
				}
			}

			function getStickyOffset() {
				var offset = 20;

				$( 'body' ).find( 'header, #masthead, .site-header' ).not( $portal.find( 'header' ) ).each( function () {
					var style = window.getComputedStyle ? window.getComputedStyle( this ) : null;
					if ( ! style || ( style.position !== 'fixed' && style.position !== 'sticky' ) ) {
						return;
					}
					var rect = this.getBoundingClientRect();
					if ( rect.top <= 2 && rect.height > 0 && rect.height < window.innerHeight * 0.45 ) {
						offset = Math.max( offset, Math.round( rect.height ) + 16 );
					}
				} );

				return offset;
			}

			function scrollPortalToTop() {
				if ( ! $portal.length ) {
					return;
				}
				var top = Math.max( 0, Math.round( $portal.offset().top - getStickyOffset() ) );
				window.scrollTo( 0, top );
			}

			function syncPanelNotices( panelName ) {
				$panelNotices.each( function () {
					var $notice = $( this );
					if ( ! $.contains( document, this ) ) {
						return;
					}

					var target = String( $notice.attr( 'data-affilio-notice-panel' ) || '' );
					var active = ! target || target === panelName;

					if ( active ) {
						$notice.prop( 'hidden', false ).attr( 'aria-hidden', 'false' ).attr( 'data-affilio-notice-shown', '1' );
						return;
					}

					if ( $notice.is( '[data-affilio-ephemeral-notice]' ) && $notice.attr( 'data-affilio-notice-shown' ) === '1' ) {
						$notice.remove();
						return;
					}

					$notice.prop( 'hidden', true ).attr( 'aria-hidden', 'true' );
				} );
			}

			function focusPanelHeading( panelName ) {
				var $activePanel = $panels.filter( '[data-affilio-panel="' + panelName + '"]' ).first();
				var $heading = $activePanel.find( 'h2, h3' ).first();
				if ( ! $heading.length ) {
					return;
				}
				if ( ! $heading.is( '[tabindex]' ) ) {
					$heading.attr( 'tabindex', '-1' );
				}

				try {
					$heading.get( 0 ).focus( { preventScroll: true } );
				} catch ( error ) {
					$heading.get( 0 ).focus();
				}
			}

			function activatePanel( panelName, options ) {
				options = options || {};
				if ( ! panelExists( panelName ) ) {
					panelName = 'overview';
				}

				$portal.attr( 'data-affilio-active-panel', panelName );

				$panels.each( function () {
					var $panel = $( this );
					var active = String( $panel.attr( 'data-affilio-panel' ) || '' ) === panelName;
					$panel.prop( 'hidden', ! active ).attr( 'aria-hidden', active ? 'false' : 'true' );
				} );

				$navLinks.each( function () {
					var $link = $( this );
					var active = String( $link.attr( 'data-affilio-panel-target' ) || '' ) === panelName;
					if ( active ) {
						$link.attr( 'aria-current', 'page' );
					} else {
						$link.removeAttr( 'aria-current' );
					}
				} );

				// Keep the selected item visible in the mobile horizontal navigation.
				var list = $nav.find( 'ul' ).get( 0 );
				var currentLink = $navLinks.filter( '[aria-current="page"]' ).get( 0 );
				if ( list && currentLink && list.scrollWidth > list.clientWidth ) {
					var bounds = list.getBoundingClientRect();
					var item = currentLink.getBoundingClientRect();
					if ( item.right > bounds.right ) {
						list.scrollLeft += item.right - bounds.right;
					} else if ( item.left < bounds.left ) {
						list.scrollLeft -= bounds.left - item.left;
					}
				}

				if ( options.updateHash !== false ) {
					updateStableHash( panelName, options.pushState === true );
				}

				syncPanelNotices( panelName );

				if ( options.focusPanel ) {
					focusPanelHeading( panelName );
				}

				if ( options.scrollToTop ) {
					window.requestAnimationFrame( scrollPortalToTop );
				}
			}

			$portal.addClass( 'is-portal-enhanced' );
			activatePanel( initialPanel(), { updateHash: false } );

			$portal.on( 'click', '[data-affilio-panel-target]', function ( event ) {
				var panelName = String( $( this ).attr( 'data-affilio-panel-target' ) || '' );
				if ( ! panelName || ! panelExists( panelName ) ) {
					return;
				}

				event.preventDefault();
				activatePanel( panelName, { updateHash: true, pushState: true, focusPanel: true, scrollToTop: true } );
			} );

			$portal.on( 'click', 'a[href^="#"]:not([data-affilio-panel-target])', function ( event ) {
				var selector = String( $( this ).attr( 'href' ) || '' );
				if ( selector.length < 2 ) {
					return;
				}

				var $target = targetFromHash( selector );
				if ( ! $target.length ) {
					return;
				}

				var $owner = $target.is( '[data-affilio-panel]' ) ? $target : $target.closest( '[data-affilio-panel]' );
				if ( ! $owner.length ) {
					return;
				}

				var panelName = String( $owner.attr( 'data-affilio-panel' ) || '' );
				if ( ! panelName ) {
					return;
				}

				event.preventDefault();
				activatePanel( panelName, { updateHash: true, pushState: true, focusPanel: true, scrollToTop: true } );
			} );

			$( window ).on( 'popstate.affilioPortal hashchange.affilioPortal', function () {
				var panelName = panelFromHash() || initialPanel();
				activatePanel( panelName, { updateHash: false, scrollToTop: true } );
			} );
		} );
	}

	$( function () {
		initRegistration();
		initPayoutProfiles();
		initLinkGenerator();
		initCopyValues();
		initAffiliatePortal();
	} );
} )( jQuery );
