/**
 * Post Type Selector React component.
 *
 * Provides a dropdown selector for choosing post types from external WordPress
 * sites, with automatic loading and error handling.
 *
 * @file This file defines the PostTypeSelector component for the Safe Publish plugin.
 */
import { ApiResponse } from './types';
import { getErrorMessage } from './utils';
import { Button, SelectControl, Notice, Spinner } from '@wordpress/components';
import {
	createInterpolateElement,
	useCallback,
	useEffect,
	useState,
} from '@wordpress/element';
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
	const getExternalSiteUrl = useCallback( (): string => {
		// Use saved settings from window.safePublishAdminData.
		return window.safePublishAdminData?.siteUrl || siteUrl || '';
	}, [ siteUrl ] );

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
	const makeRequest = useCallback( async (
		action: string,
		data: Record< string, string | number > = {}
	): Promise< unknown > => {
		const formData = new FormData();
		formData.append( 'action', action );
		formData.append( 'nonce', window.safePublishAdminData.nonce );

		Object.entries( data ).forEach( ( [ key, value ] ) => {
			formData.append( key, String( value ) );
		} );

		const response = await fetch( window.safePublishAdminData.ajaxurl, {
			method: 'POST',
			body: formData,
		} );

		return response.json();
	}, [] );

	/**
	 * Loads available post types from the external site.
	 *
	 * Fetches the list of public post types from the external WordPress site
	 * and updates the component state with the available options.
	 *
	 * @return {Promise<void>} Resolves when post types are loaded.
	 */
	const loadPostTypes = useCallback( async (): Promise< void > => {
		const currentSiteUrl = getExternalSiteUrl();

		if ( ! currentSiteUrl ) {
			setPostTypes( [] );
			setError( null );
			return;
		}

		setIsLoading( true );
		setError( null );

		try {
			const response = await makeRequest(
				'safe_publish_fetch_post_types',
				{ site_url: currentSiteUrl }
			) as ApiResponse< Record< string, PostTypeOption > >;

			if ( response.success ) {
				// Convert object to array - don't filter out anything initially.
				const postTypeArray = Object.values( response.data );

				// Set the post types directly from the API response.
				setPostTypes( postTypeArray );
				setLastSiteUrl( currentSiteUrl );
			} else {
				// eslint-disable-next-line no-console
				console.error( 'Safe Publish PostTypeSelector: API error:', response );
				setError( getErrorMessage( response, __( 'Failed to load post types', 'safe-publish' ) ) );
				setPostTypes( [] );
			}
		} catch ( err ) {
			// eslint-disable-next-line no-console
			console.error( 'Safe Publish PostTypeSelector: Network error:', err );
			/* translators: %s is the error message */
			setError( __( 'Network error while loading post types: %s', 'safe-publish' ).replace( '%s', String( err ) ) );
			setPostTypes( [] );
		} finally {
			setIsLoading( false );
		}
	}, [ getExternalSiteUrl, makeRequest ] );

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
	}, [ siteUrl, loadPostTypes ] );

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
	}, [ lastSiteUrl, getExternalSiteUrl, loadPostTypes ] );

	// Generate options for the select control.
	const selectOptions = postTypes.map( postType => ( {
		label: postType.label,
		value: postType.rest_base,
	} ) );

	// Always ensure we have at least the default "posts" option.
	if ( 0 === selectOptions.length && ! isLoading ) {
		selectOptions.push( {
			label: __( 'Posts (default)', 'safe-publish' ),
			value: 'posts',
		} );
	}

	return (
		<div className="safe-publish-post-type-selector" style={ { marginBottom: '10px' } }>
			<div style={ { display: 'flex', alignItems: 'center', gap: '10px' } }>
				<SelectControl
					label={ __( 'Post Type:', 'safe-publish' ) }
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
					style={ { marginTop: '15px', padding: '16px', fontSize: 'unset' } }
				>
					{ isLoading ? (
						<>
							<Spinner />
							{ __( 'Loading…', 'safe-publish' ) }
						</>
					) : (
						__( 'Refresh', 'safe-publish' )
					) }
				</Button>
			</div>

			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			{ ! getExternalSiteUrl() && (
				<Notice status="info">
					{ createInterpolateElement(
						__( 'Please enter a site URL in the <link>settings page</link> to load available post types.', 'safe-publish' ),
						{
							link: <a href={ window.safePublishAdminData?.settingsUrl || '/wp-admin/admin.php?page=safe-publish-settings' }>link</a>,
						}
					) }
				</Notice>
			) }
		</div>
	);
}
