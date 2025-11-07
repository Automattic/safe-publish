/**
 * Admin Tools React component for handling test connection and preview posts
 */
import { Button, Notice, Spinner } from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import React from 'react';

import { PostTypeSelector } from './post-type-selector';
import type { Post } from './types';

// Reference to global types
/// <reference path="../types/globals.d.ts" />

// Extend the window interface
declare global {
	interface Window {
		ccpRefreshPosts?: ( postType?: string ) => void;
	}
}

interface AdminToolsProps {
	siteUrl: string;
	numberPosts: number;
}

interface TestResult {
	success: boolean;
	message: string;
	response_time?: number;
}

interface PreviewResult {
	type: string;
	message: string;
	posts?: Post[];
}

interface ApiResponse< T > {
	success: boolean;
	data: T;
}

/**
 * Admin Tools component for testing connection and previewing posts
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

	// Auto-clear notices after 5 seconds
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

	// Get current site URL from saved settings instead of form input
	const getExternalSiteUrl = (): string => {
		// Use saved settings from window.ccpAdminData
		return window.ccpAdminData?.siteUrl || initialSiteUrl || '';
	};

	/**
	 * Handle post type change from PostTypeSelector
	 */
	const handlePostTypeChange = ( postType: string ): void => {
		setSelectedPostType( postType );
		// Trigger DataViews refresh with new post type
		if ( typeof window.ccpRefreshPosts === 'function' ) {
			window.ccpRefreshPosts( postType );
		}
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
	 * Test connection to external site
	 */
	const handleTestConnection = async (): Promise< void > => {
		const siteUrl = getExternalSiteUrl();

		if ( ! siteUrl ) {
			setTestResult( {
				success: false,
				message: __( 'Please enter a site URL first.', 'ccp' ),
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
	 * Preview posts from external site
	 */
	const handlePreviewPosts = async (): Promise< void > => {
		const siteUrl = getExternalSiteUrl();

		if ( ! siteUrl ) {
			setPreviewResult( {
				type: 'error',
				message: __( 'Please enter a site URL first.', 'ccp' ),
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
						message: __(
							`Found ${ response.data.length } posts from post type: ${ selectedPostType }`,
							'ccp'
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

	// Wrapper functions to handle void returns for onClick
	const onTestClick = (): void => {
		handleTestConnection().catch( console.error );
	};

	const onPreviewClick = (): void => {
		handlePreviewPosts().catch( console.error );
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
							{ __( 'Testing...', 'ccp' ) }
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
							{ __( 'Loading...', 'ccp' ) }
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
