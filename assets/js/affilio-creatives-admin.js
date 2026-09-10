( function () {
	'use strict';

	const workspace = document.querySelector( '.affilio-creative-workspace' );

	if ( ! workspace ) {
		return;
	}

	const fields = {
		title: document.getElementById( 'title' ),
		destination: document.getElementById( 'affilio-creative-destination' ),
		campaign: document.getElementById( 'affilio-creative-campaign' ),
		cta: document.getElementById( 'affilio-creative-cta' ),
		image: document.getElementById( 'affilio-creative-image-url' ),
	};
	const preview = {
		title: workspace.querySelector( '.affilio-creative-preview__title' ),
		destination: workspace.querySelector( '.affilio-creative-preview__destination' ),
		campaign: workspace.querySelector( '.affilio-creative-preview__campaign' ),
		cta: workspace.querySelector( '.affilio-creative-preview__cta span' ),
		canvas: workspace.querySelector( '.affilio-creative-preview__canvas' ),
		image: workspace.querySelector( '.affilio-creative-preview__canvas img' ),
		placeholder: workspace.querySelector( '.affilio-creative-preview__placeholder' ),
		placeholderTitle: workspace.querySelector( '.affilio-creative-preview__placeholder strong' ),
		placeholderNote: workspace.querySelector( '.affilio-creative-preview__placeholder small' ),
	};

	if ( ! fields.destination || ! fields.campaign || ! fields.cta || ! fields.image || ! preview.canvas ) {
		return;
	}

	const text = {
		title: workspace.dataset.emptyTitle || 'Creative title',
		campaign: workspace.dataset.emptyCampaign || 'No campaign label',
		cta: workspace.dataset.emptyCta || 'Learn More',
		externalImage: workspace.dataset.externalImage || 'External fallback saved',
		externalNote: workspace.dataset.externalNote || 'External images are not loaded in this admin preview for privacy.',
		imagePrompt: workspace.dataset.imagePrompt || 'Add a Featured Image',
		imageNote: workspace.dataset.imageNote || 'A link-only creative remains fully supported.',
	};
	const homeHost = ( workspace.dataset.homeHost || window.location.hostname ).toLowerCase();
	const featuredImage = preview.canvas.dataset.featuredImage || '';

	function valueOr( field, fallback ) {
		return field && field.value.trim() ? field.value.trim() : fallback;
	}

	function isSameSiteImage( value ) {
		if ( ! value ) {
			return false;
		}

		try {
			const url = new URL( value, window.location.origin );
			return ( 'http:' === url.protocol || 'https:' === url.protocol ) && url.hostname.toLowerCase() === homeHost;
		} catch ( error ) {
			return false;
		}
	}

	function showImage( source ) {
		if ( ! preview.image || ! preview.placeholder ) {
			return;
		}

		preview.image.src = source;
		preview.image.hidden = false;
		preview.placeholder.hidden = true;
		preview.canvas.classList.add( 'has-image' );
		preview.canvas.classList.remove( 'has-no-image' );
	}

	function showPlaceholder( isExternal ) {
		if ( ! preview.image || ! preview.placeholder ) {
			return;
		}

		preview.image.removeAttribute( 'src' );
		preview.image.hidden = true;
		preview.placeholder.hidden = false;
		preview.placeholderTitle.textContent = isExternal ? text.externalImage : text.imagePrompt;
		preview.placeholderNote.textContent = isExternal ? text.externalNote : text.imageNote;
		preview.canvas.classList.remove( 'has-image' );
		preview.canvas.classList.add( 'has-no-image' );
	}

	function updatePreview() {
		if ( preview.title ) {
			preview.title.textContent = valueOr( fields.title, text.title );
		}
		if ( preview.destination ) {
			preview.destination.textContent = valueOr( fields.destination, fields.destination.placeholder );
		}
		if ( preview.campaign ) {
			preview.campaign.textContent = valueOr( fields.campaign, text.campaign );
		}
		if ( preview.cta ) {
			preview.cta.textContent = valueOr( fields.cta, text.cta );
		}

		const fallbackImage = fields.image.value.trim();
		if ( featuredImage ) {
			showImage( featuredImage );
		} else if ( isSameSiteImage( fallbackImage ) ) {
			showImage( fallbackImage );
		} else {
			showPlaceholder( Boolean( fallbackImage ) );
		}
	}

	Object.values( fields ).forEach( function ( field ) {
		if ( field ) {
			field.addEventListener( 'input', updatePreview );
			field.addEventListener( 'change', updatePreview );
		}
	} );

	if ( preview.image ) {
		preview.image.addEventListener( 'error', function () {
			showPlaceholder( false );
		} );
	}

	updatePreview();
}() );
