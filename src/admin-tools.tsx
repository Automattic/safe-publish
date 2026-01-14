/**
 * Admin Tools React component for handling test connection and preview posts.
 *
 * Provides UI for testing the connection to external WordPress sites and
 * previewing available posts before import.
 *
 * @file This file defines the AdminTools component for the CCP plugin.
 */
import { PostTypeSelector } from './post-type-selector';
import { Button, Notice, Spinner } from '@wordpress/components';
import { useState, useEffect, createInterpolateElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { Post } from './types';
import type { ReactNode } from 'react';

/**
 * Props for the AdminTools component.
 *
 * @property {string} siteUrl     Initial external site URL.
 * @property {number} numberPosts Number of posts to fetch.
 */
interface AdminToolsProps {
	siteUrl: string;
	numberPosts: number;
}

/**
 * Result from a connection test request.
 *
 * @property {boolean}          success         Whether the test succeeded.
 * @property {string|ReactNode} message         Result message to display.
 * @property {number}           [response_time] Response time in milliseconds.
 */
interface TestResult {
	success: boolean;
	message: string | ReactNode;
	response_time?: number;
}

/**
 * Result from a preview posts request.
 *
 * @property {string}           type    Result type.
 * @property {string|ReactNode} message Result message to display.
 * @property {Post[]}           [posts] Previewed posts.
 */
interface PreviewResult {
	type: string;
	message: string | ReactNode;
	posts?: Post[];
}

/**
 * Admin Tools component for testing connection and previewing posts.
 *
 * Renders buttons for testing the connection to the external site, previewing
 * available posts, and selecting post types.
 *
 * @param {Object} props             Component props.
 * @param {string} props.siteUrl     Initial external site URL.
 * @param {number} props.numberPosts Number of posts to fetch.
 *
 * @return {JSX.Element} Rendered AdminTools component.
 */
export function AdminTools( {
	siteUrl: initialSiteUrl,
	numberPosts,
}: AdminToolsProps ): JSX.Element {
	const [ testLoading, setTestLoading ] = useState( false );
	const [ testResult, setTestResult ] = useState< TestResult | null >( null );

	const [ previewLoading, setPreviewLoading ] = useState( false );
	const [ previewResult, setPreviewResult ] = useState< PreviewResult | null >( null );

	const [ selectedPostType, setSelectedPostType ] = useState( 'posts' );

	// Auto-clear notices after 5 seconds.
	useEffect( () => {
		if ( testResult ) {
			const timer = setTimeout( () => {
				setTestResult( null );
			}, 5000 );
			return () => clearTimeout( timer );
		}
	}, [ testResult ] );

	useEffect( () => {
		if ( previewResult ) {
			const timer = setTimeout( () => {
				setPreviewResult( null );
			}, 5000 );
			return () => clearTimeout( timer );
		}
	}, [ previewResult ] );

	// Get current site URL from saved settings instead of form input.
	const getExternalSiteUrl = (): string => {
		// Use saved settings from window.ccpAdminData.
		return window.ccpAdminData?.siteUrl || initialSiteUrl || '';
	};

	/**
	 * Handles post type change from PostTypeSelector.
	 *
	 * Updates the selected post type and triggers a DataViews refresh to load
	 * posts of the new type.
	 *
	 * @param {string} postType Newly selected post type.
	 */
	const handlePostTypeChange = ( postType: string ): void => {
		setSelectedPostType( postType );
		// Trigger DataViews refresh with new post type.
		if ( typeof window.ccpRefreshPosts === 'function' ) {
			window.ccpRefreshPosts( postType );
		}
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
	 * Tests connection to the external site.
	 *
	 * Sends a test request to the external WordPress site and displays the
	 * connection result.
	 *
	 * @return {Promise<void>} Resolves when the test completes.
	 */
	const handleTestConnection = async (): Promise< void > => {
		const siteUrl = getExternalSiteUrl();

		if ( ! siteUrl ) {
			const settingsUrl = window.ccpAdminData?.settingsUrl || '/wp-admin/admin.php?page=ccp-settings';
			setTestResult( {
				success: false,
				message: createInterpolateElement(
					__( 'Please enter a site URL in the <link>settings page</link> first.', 'ccp' ),
					{
						link: <a href={ settingsUrl }>link</a>,
					}
				),
			} );
			return;
		}

		setTestLoading( true );
		setTestResult( null );

		try {
			const response = await makeRequest( 'ccp_test_connection', {
				site_url: siteUrl,
			} );

			if ( response.success ) {
				const message = response.data.response_time
					? `${ response.data.message } (Response time: ${ response.data.response_time }ms)`
					: response.data.message;

				setTestResult( {
					success: response.data.success,
					message,
				} );
			} else {
				setTestResult( {
					success: false,
					message: response.data?.message || __( 'Connection test failed.', 'ccp' ),
				} );
			}
		} catch ( error ) {
			setTestResult( {
				success: false,
				message: __( 'Network error occurred during connection test.', 'ccp' ),
			} );
		} finally {
			setTestLoading( false );
		}
	};

	/**
	 * Previews posts from the external site.
	 *
	 * Fetches a sample of posts from the external WordPress site using the
	 * selected post type and displays them.
	 *
	 * @return {Promise<void>} Resolves when the preview completes.
	 */
	const handlePreviewPosts = async (): Promise< void > => {
		const siteUrl = getExternalSiteUrl();

		if ( ! siteUrl ) {
			const settingsUrl = window.ccpAdminData?.settingsUrl || '/wp-admin/admin.php?page=ccp-settings';
			setPreviewResult( {
				type: 'error',
				message: createInterpolateElement(
					__( 'Please enter a site URL in the <link>settings page</link> first.', 'ccp' ),
					{
						link: <a href={ settingsUrl }>link</a>,
					}
				),
			} );
			return;
		}

		setPreviewLoading( true );
		setPreviewResult( null );

		try {
			const response = await makeRequest( 'ccp_fetch_posts', {
				site_url: siteUrl,
				number_of_posts: numberPosts,
				post_type: selectedPostType,
			} );

			if ( response.success ) {
				if ( response.data.length > 0 ) {
					setPreviewResult( {
						type: 'success',
						message: sprintf(
							/* translators: 1: number of posts, 2: post type name */
							__( 'Found %1$d posts from post type: %2$s', 'ccp' ),
							response.data.length,
							selectedPostType
						),
						posts: response.data,
					} );
				} else {
					setPreviewResult( {
						type: 'info',
						message: __( 'No posts found for the selected post type.', 'ccp' ),
					} );
				}
			} else {
				setPreviewResult( {
					type: 'error',
					message: __( 'Failed to fetch posts.', 'ccp' ),
				} );
			}
		} catch ( error ) {
			setPreviewResult( {
				type: 'error',
				message: __( 'Network error occurred while fetching posts.', 'ccp' ),
			} );
		} finally {
			setPreviewLoading( false );
		}
	};

	/**
	 * Click handler for the test connection button.
	 *
	 * Wraps the async handleTestConnection function for use as an onClick handler.
	 */
	const onTestClick = (): void => {
		handleTestConnection().catch( ( error ) => {
			// Only unexpected errors should reach here.
			// eslint-disable-next-line no-console
			console.error( 'Unexpected error in handleTestConnection:', error );
		} );
	};

	/**
	 * Click handler for the preview posts button.
	 *
	 * Wraps the async handlePreviewPosts function for use as an onClick handler.
	 */
	const onPreviewClick = (): void => {
		handlePreviewPosts().catch( ( error ) => {
			// Only unexpected errors should reach here.
			// eslint-disable-next-line no-console
			console.error( 'Unexpected error in handlePreviewPosts:', error );
		} );
	};

	return (
		<div className="ccp-admin-tools">
			{ /* Test Connection */ }
			<div className="ccp-tool">
				<h3>{ __( 'Test Connection', 'ccp' ) }</h3>
				<p>{ __( 'Test the connection to the non-prod site API.', 'ccp' ) }</p>
				<Button variant="secondary" onClick={ onTestClick } disabled={ testLoading }>
					{ testLoading ? (
						<>
							<Spinner />
							{ __( 'Testing…', 'ccp' ) }
						</>
					) : (
						__( 'Test Connection', 'ccp' )
					) }
				</Button>
				{ testResult && (
					<Notice
						status={ testResult.success ? 'success' : 'error' }
						isDismissible={ false }
						className="ccp-test-result"
					>
						{ testResult.message }
					</Notice>
				) }
			</div>

			{ /* Preview Posts */ }
			<div className="ccp-tool">
				<h3>{ __( 'Preview Posts', 'ccp' ) }</h3>
				<p>{ __( 'Preview posts that will be fetched with current settings.', 'ccp' ) }</p>

				<PostTypeSelector
					siteUrl={ getExternalSiteUrl() }
					selectedPostType={ selectedPostType }
					onPostTypeChange={ handlePostTypeChange }
				/>

				<Button variant="secondary" onClick={ onPreviewClick } disabled={ previewLoading }>
					{ previewLoading ? (
						<>
							<Spinner />
							{ __( 'Loading…', 'ccp' ) }
						</>
					) : (
						__( 'Preview Posts', 'ccp' )
					) }
				</Button>
				{ previewResult && (
					<>
						<Notice
							status={ previewResult.type as 'success' | 'error' | 'info' | 'warning' }
							isDismissible={ false }
							className="ccp-preview-result"
						>
							{ previewResult.message }
						</Notice>
						{ previewResult.posts && previewResult.posts.length > 0 && (
							<div className="ccp-preview-posts">
								{ previewResult.posts.map( post => (
									<div key={ post.id } className="ccp-preview-post">
										<span className="ccp-preview-post-title">{ post.title }</span>
										<a
											href={ post.link }
											target="_blank"
											rel="noopener noreferrer"
											className="ccp-preview-post-link"
										>
											{ __( 'View', 'ccp' ) }
										</a>
									</div>
								) ) }
							</div>
						) }
					</>
				) }
			</div>
		</div>
	);
}
