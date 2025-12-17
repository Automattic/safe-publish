/**
 * Post Type Selector React component
 */
import { Button, SelectControl, Notice, Spinner } from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import React from 'react';

interface PostTypeOption {
	slug: string;
	name: string;
	label: string;
	rest_base: string;
	description?: string;
}

interface PostTypeSelectorProps {
	siteUrl: string;
	onPostTypeChange?: ( postType: string ) => void;
	selectedPostType?: string;
}

interface ApiResponse< T > {
	success: boolean;
	data: T;
}

/**
 * Post Type Selector component for choosing post types from external sites
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
	 * Get current site URL from saved settings (more reliable than input field)
	 */
	const getExternalSiteUrl = (): string => {
		// Use saved settings from window.ccpAdminData.
		return window.ccpAdminData?.siteUrl || siteUrl || '';
	};

	/**
	 * Make AJAX request to WordPress
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
	 * Load available post types from the external site
	 */
	const loadPostTypes = async (): Promise< void > => {
		const currentSiteUrl = getExternalSiteUrl();

		if ( ! currentSiteUrl ) {
			setPostTypes( [] );
			setError( null );
			return;
		}

		console.log( 'CCP PostTypeSelector: Loading post types for:', currentSiteUrl );
		setIsLoading( true );
		setError( null );

		try {
			const response: ApiResponse< Record< string, PostTypeOption > > = await makeRequest(
				'ccp_fetch_post_types',
				{ site_url: currentSiteUrl }
			);

			console.log( 'CCP PostTypeSelector: API response:', response );

			if ( response.success && response.data ) {
				// Convert object to array - don't filter out anything initially.
				const postTypeArray = Object.values( response.data );

				console.log( 'CCP PostTypeSelector: Post types from API:', postTypeArray );

				// Set the post types directly from the API response.
				setPostTypes( postTypeArray );
				setLastSiteUrl( currentSiteUrl );
			} else {
				console.error( 'CCP PostTypeSelector: API error:', response );
				const errorMessage =
					typeof response.data === 'string'
						? response.data
						: response.data
						? JSON.stringify( response.data )
						: 'Failed to load post types';
				setError( errorMessage );
				setPostTypes( [] );
			}
		} catch ( err ) {
			console.error( 'CCP PostTypeSelector: Network error:', err );
			setError( __( 'Network error while loading post types: ' + String( err ), 'ccp' ) );
			setPostTypes( [] );
		} finally {
			setIsLoading( false );
		}
	};

	/**
	 * Handle post type selection change
	 */
	const handlePostTypeChange = ( postType: string ): void => {
		setCurrentPostType( postType );
		if ( onPostTypeChange ) {
			onPostTypeChange( postType );
		}
	};

	/**
	 * Handle refresh button click
	 */
	const handleRefresh = (): void => {
		loadPostTypes().catch( console.error );
	};

	// Load post types when site URL changes.
	useEffect( () => {
		loadPostTypes().catch( console.error );
	}, [ siteUrl ] );

	// Also check for changes in the form input field periodically.
	useEffect( () => {
		const checkSiteUrlChange = () => {
			const currentSiteUrl = getExternalSiteUrl();
			if ( currentSiteUrl !== lastSiteUrl && currentSiteUrl ) {
				console.log(
					'CCP PostTypeSelector: Site URL changed from',
					lastSiteUrl,
					'to',
					currentSiteUrl
				);
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
							{ __( 'Loading...', 'ccp' ) }
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
					{ __( 'Please enter a site URL in the ', 'ccp' ) }
					<a href={ window.ccpAdminData?.settingsUrl || '/wp-admin/admin.php?page=ccp-settings' }>
						{ __( 'settings page', 'ccp' ) }
					</a>
					{ __( ' to load available post types.', 'ccp' ) }
				</Notice>
			) }
		</div>
	);
}
