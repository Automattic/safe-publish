/**
 * Post Type Selector React component.
 *
 * Provides a dropdown selector for choosing post types from external WordPress
 * sites, with automatic loading and error handling.
 *
 * @file This file defines the PostTypeSelector component for the CCP plugin.
 */
import { Button, SelectControl, Notice, Spinner } from '@wordpress/components';
import { useState, useEffect, createInterpolateElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Represents a post type option from the external site.
 *
 * @property {string} slug          Post type slug.
 * @property {string} name          Post type name.
 * @property {string} label         Display label.
 * @property {string} rest_base     REST API base path.
 * @property {string} [description] Optional description.
 */
interface PostTypeOption {
	slug: string;
	name: string;
	label: string;
	rest_base: string;
	description?: string;
}

/**
 * Props for the PostTypeSelector component.
 *
 * @property {string}   siteUrl            External site URL.
 * @property {Function} [onPostTypeChange] Callback when post type changes.
 * @property {string}   [selectedPostType] Initially selected post type.
 */
interface PostTypeSelectorProps {
	siteUrl: string;
	onPostTypeChange?: ( postType: string ) => void;
	selectedPostType?: string;
}

/**
 * Generic wrapper for API responses.
 *
 * @template T
 * @property {boolean} success Whether the request succeeded.
 * @property {T}       data    Response data.
 */
interface ApiResponse< T > {
	success: boolean;
	data: T;
}

/**
 * Post Type Selector component for choosing post types from external sites.
 *
 * Fetches available post types from the external WordPress site and provides a
 * dropdown for selection with automatic refresh on site URL change.
 *
 * @param {Object}   props                    Component props.
 * @param {string}   props.siteUrl            External site URL.
 * @param {Function} [props.onPostTypeChange] Callback when post type changes.
 * @param {string}   [props.selectedPostType] Initially selected post type.
 *
 * @return {JSX.Element} Rendered PostTypeSelector component.
 */
export function PostTypeSelector( {
	siteUrl,
	onPostTypeChange,
	selectedPostType = 'posts',
}: PostTypeSelectorProps ): JSX.Element {
	const [ postTypes, setPostTypes ] = useState< PostTypeOption[] >( [] );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ currentPostType, setCurrentPostType ] = useState( selectedPostType );
	const [ lastSiteUrl, setLastSiteUrl ] = useState( siteUrl );

	/**
	 * Gets the current site URL from saved settings.
	 *
	 * Retrieves the external site URL from the global admin data, which is more
	 * reliable than reading from input fields.
	 *
	 * @return {string} External site URL.
	 */
	const getExternalSiteUrl = (): string => {
		// Use saved settings from window.ccpAdminData.
		return window.ccpAdminData?.siteUrl || siteUrl || '';
	};

	/**
	 * Makes an AJAX request to WordPress.
	 *
	 * Sends a POST request to the WordPress AJAX endpoint with the specified
	 * action and data.
	 *
	 * @param {string}                        action AJAX action to perform.
	 * @param {Record<string, string|number>} data   Additional request data.
	 * @return {Promise<any>} JSON response from the server.
	 */
	const makeRequest = async (
		action: string,
		data: Record< string, string | number > = {}
	): Promise< any > => {
		const formData = new FormData();
		formData.append( 'action', action );
		formData.append( 'nonce', window.ccpAdminData.nonce );

		Object.entries( data ).forEach( ( [ key, value ] ) => {
			formData.append( key, String( value ) );
		} );

		const response = await fetch( window.ccpAdminData.ajaxurl, {
			method: 'POST',
			body: formData,
		} );

		return response.json();
	};

	/**
	 * Loads available post types from the external site.
	 *
	 * Fetches the list of public post types from the external WordPress site
	 * and updates the component state with the available options.
	 *
	 * @return {Promise<void>} Resolves when post types are loaded.
	 */
	const loadPostTypes = async (): Promise< void > => {
		const currentSiteUrl = getExternalSiteUrl();

		if ( ! currentSiteUrl ) {
			setPostTypes( [] );
			setError( null );
			return;
		}

		setIsLoading( true );
		setError( null );

		try {
			const response: ApiResponse< Record< string, PostTypeOption > > = await makeRequest(
				'ccp_fetch_post_types',
				{ site_url: currentSiteUrl }
			);

			if ( response.success && response.data ) {
				// Convert object to array - don't filter out anything initially.
				const postTypeArray = Object.values( response.data );

				// Set the post types directly from the API response.
				setPostTypes( postTypeArray );
				setLastSiteUrl( currentSiteUrl );
			} else {
				// eslint-disable-next-line no-console
				console.error( 'CCP PostTypeSelector: API error:', response );
				let errorMessage;
				if ( typeof response.data === 'string' ) {
					errorMessage = response.data;
				} else if ( response.data ) {
					errorMessage = JSON.stringify( response.data );
				} else {
					errorMessage = 'Failed to load post types';
				}
				setError( errorMessage );
				setPostTypes( [] );
			}
		} catch ( err ) {
			// eslint-disable-next-line no-console
			console.error( 'CCP PostTypeSelector: Network error:', err );
			/* translators: %s is the error message */
			setError( __( 'Network error while loading post types: %s', 'ccp' ).replace( '%s', String( err ) ) );
			setPostTypes( [] );
		} finally {
			setIsLoading( false );
		}
	};

	/**
	 * Handles post type selection change.
	 *
	 * Updates the current post type state and calls the parent callback.
	 *
	 * @param {string} postType Newly selected post type.
	 */
	const handlePostTypeChange = ( postType: string ): void => {
		setCurrentPostType( postType );
		if ( onPostTypeChange ) {
			onPostTypeChange( postType );
		}
	};

	/**
	 * Handles refresh button click.
	 *
	 * Triggers a reload of available post types from the external site.
	 */
	const handleRefresh = (): void => {
		// eslint-disable-next-line no-console
		loadPostTypes().catch( console.error );
	};

	// Load post types when site URL changes.
	useEffect( () => {
		// eslint-disable-next-line no-console
		loadPostTypes().catch( console.error );
	}, [ siteUrl ] );

	// Also check for changes in the form input field periodically.
	useEffect( () => {
		const checkSiteUrlChange = () => {
			const currentSiteUrl = getExternalSiteUrl();
			if ( currentSiteUrl !== lastSiteUrl && currentSiteUrl ) {
				// eslint-disable-next-line no-console
				loadPostTypes().catch( console.error );
			}
		};

		// Check every 2 seconds for URL changes.
		const interval = setInterval( checkSiteUrlChange, 2000 );

		// Also check immediately.
		checkSiteUrlChange();

		return () => clearInterval( interval );
	}, [ lastSiteUrl ] );

	// Generate options for the select control.
	const selectOptions = postTypes.map( postType => ( {
		label: postType.label,
		value: postType.rest_base,
	} ) );

	// Always ensure we have at least the default "posts" option.
	if ( selectOptions.length === 0 && ! isLoading ) {
		selectOptions.push( {
			label: __( 'Posts (default)', 'ccp' ),
			value: 'posts',
		} );
	}

	return (
		<div className="ccp-post-type-selector" style={ { marginBottom: '10px' } }>
			<div style={ { display: 'flex', alignItems: 'center', gap: '10px' } }>
				<SelectControl
					label={ __( 'Post Type:', 'ccp' ) }
					value={ currentPostType }
					options={ selectOptions }
					onChange={ handlePostTypeChange }
					disabled={ isLoading }
					style={ { minWidth: '150px' } }
				/>
				<Button
					variant="secondary"
					size="small"
					onClick={ handleRefresh }
					disabled={ isLoading || ! siteUrl }
				>
					{ isLoading ? (
						<>
							<Spinner />
							{ __( 'Loading…', 'ccp' ) }
						</>
					) : (
						__( 'Refresh', 'ccp' )
					) }
				</Button>
			</div>

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ ! getExternalSiteUrl() && (
				<Notice status="info" isDismissible={ false }>
					{ createInterpolateElement(
						__( 'Please enter a site URL in the <link>settings page</link> to load available post types.', 'ccp' ),
						{
							link: <a href={ window.ccpAdminData?.settingsUrl || '/wp-admin/admin.php?page=ccp-settings' }>link</a>,
						}
					) }
				</Notice>
			) }
		</div>
	);
}
