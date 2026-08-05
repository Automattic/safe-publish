/**
 * Post Type Selector React component.
 *
 * Provides a dropdown selector for choosing post types from source WordPress
 * sites, with automatic loading and error handling.
 *
 * @file This file defines the PostTypeSelector component for the Safe Publish plugin.
 */
import { ApiResponse } from './types';
import { getErrorMessage } from './utils';
import { Notice, SelectControl } from '@wordpress/components';
import {
	createInterpolateElement,
	useCallback,
	useEffect,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Represents a post type option from the source site.
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
 * @property {string}   sourceSiteUrl      Source site URL.
 * @property {Function} [onPostTypeChange] Callback when post type changes.
 * @property {Function} [onError]          Callback when the error state changes.
 * @property {string}   [selectedPostType] Initially selected post type.
 */
interface PostTypeSelectorProps {
	sourceSiteUrl: string;
	onPostTypeChange?: ( postType: string ) => void;
	onError?: ( error: string | null ) => void;
	selectedPostType?: string;
}

/**
 * Post Type Selector component for choosing post types from source sites.
 *
 * Fetches available post types from the source WordPress site and provides a
 * dropdown for selection with automatic refresh on site URL change.
 *
 * @param {Object}   props                    Component props.
 * @param {string}   props.sourceSiteUrl      Source site URL.
 * @param {Function} [props.onPostTypeChange] Callback when post type changes.
 * @param {Function} [props.onError]          Callback when the error state changes.
 * @param {string}   [props.selectedPostType] Initially selected post type.
 *
 * @return {JSX.Element} Rendered PostTypeSelector component.
 */
export function PostTypeSelector( {
	sourceSiteUrl,
	onPostTypeChange,
	onError,
	selectedPostType = 'post',
}: PostTypeSelectorProps ): JSX.Element {
	const [ postTypes, setPostTypes ] = useState< PostTypeOption[] >( [] );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ currentPostType, setCurrentPostType ] = useState( selectedPostType );

	/**
	 * Gets the source site URL from saved settings.
	 *
	 * Retrieves the source site URL from the global admin data, which is more
	 * reliable than reading from input fields.
	 *
	 * @return {string} Source site URL.
	 */
	const getSourceSiteUrl = useCallback( (): string => {
		// Use saved settings from window.safePublishAdminData.
		return window.safePublishAdminData?.sourceSiteUrl || sourceSiteUrl || '';
	}, [ sourceSiteUrl ] );

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
	 * Loads available post types from the source site.
	 *
	 * Fetches the list of public post types from the source WordPress site
	 * and updates the component state with the available options.
	 *
	 * @return {Promise<void>} Resolves when post types are loaded.
	 */
	const loadPostTypes = useCallback( async (): Promise< void > => {
		const currentSourceSiteUrl = getSourceSiteUrl();

		if ( ! currentSourceSiteUrl ) {
			setPostTypes( [] );
			setError( null );
			return;
		}

		setIsLoading( true );
		setError( null );

		try {
			const response = await makeRequest(
				'safe_publish_fetch_post_types',
				{ source_site_url: currentSourceSiteUrl }
			) as ApiResponse< PostTypeOption[] >;

			if ( response.success ) {
				setPostTypes( response.data );
			} else {
				// eslint-disable-next-line no-console
				console.error( 'Safe Publish PostTypeSelector: API error:', response );
				setError( getErrorMessage( response, __( 'Failed to load post types.', 'safe-publish' ) ) );
				setPostTypes( [] );
			}
		} catch ( err ) {
			// eslint-disable-next-line no-console
			console.error( 'Safe Publish PostTypeSelector: Network error:', err );
			setError( __( 'Network error while loading post types.', 'safe-publish' ) );
			setPostTypes( [] );
		} finally {
			setIsLoading( false );
		}
	}, [ getSourceSiteUrl, makeRequest ] );

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

	// Load post types when site URL changes.
	useEffect( () => {
		// eslint-disable-next-line no-console
		loadPostTypes().catch( console.error );
	}, [ sourceSiteUrl, loadPostTypes ] );

	// Propagate error state to parent.
	useEffect( () => {
		onError?.( error );
	}, [ error, onError ] );

	// Generate options for the select control.
	// Use slug (not rest_base) as the option value: The catalog endpoint
	// expects the WP post type slug, and centralizing on slug avoids the
	// slug/rest_base translation gap for custom CPTs.
	const selectOptions = postTypes.map( postType => ( {
		label: postType.label,
		value: postType.slug,
	} ) );

	// Always ensure we have at least the default "post" option.
	if ( 0 === selectOptions.length && ! isLoading ) {
		selectOptions.push( {
			label: __( 'Posts (default)', 'safe-publish' ),
			value: 'post',
		} );
	}

	return (
		<>
			<div className="safe-publish-post-type-selector">
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Type', 'safe-publish' ) }
					value={ currentPostType }
					options={ selectOptions }
					onChange={ handlePostTypeChange }
					disabled={ isLoading }
				/>
			</div>

			{ ! getSourceSiteUrl() && (
				<Notice status="info">
					{ createInterpolateElement(
						__( 'Please enter a site URL in the <link>settings page</link> to load available post types.', 'safe-publish' ),
						{
							link: <a href={ window.safePublishAdminData?.settingsUrl || '/wp-admin/admin.php?page=safe-publish-settings' }>link</a>,
						}
					) }
				</Notice>
			) }
		</>
	);
}
