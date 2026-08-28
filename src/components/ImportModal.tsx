/**
 * Import Modal component.
 *
 * Drives the Import action on the Manage listing. An import that lands as a
 * draft starts on mount, since it changes nothing publicly visible. One that
 * overwrites a published post is confirmed first, because that updates the
 * destination site immediately.
 *
 * @file This file defines the ImportModal component.
 */

import {
	Button,
	__experimentalText as Text,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
	Spinner,
} from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { renderWarningMessage } from '../utils';
import IsolatedErrorMessage from './IsolatedErrorMessage';
import { useImportPost } from './hooks/useImportPost';
import { useRefreshOnUnmount } from './hooks/useRefreshOnUnmount';

// Fixed ids are safe: DataViews keeps one action modal open at a time.
const LIVE_HEADING_ID = 'safe-publish-import-live-heading';
const LIVE_BODY_ID = 'safe-publish-import-live-body';

/**
 * Props for the ImportModal component.
 *
 * @property {number}   sourcePostId   Source post ID to import or update.
 * @property {string}   title          Post title.
 * @property {string}   sourceLink     Source post permalink.
 * @property {string}   postType       Source post type slug.
 * @property {boolean}  isUpdate       True for the "Update" flow, false for "Import"; controls force_update + labels.
 * @property {boolean}  isLive         True when the destination post is published.
 * @property {number}   [skippedCount] Ineligible selected rows dropped before import; sizes the skipped note.
 * @property {string}   ajaxurl        WordPress admin-ajax URL.
 * @property {string}   nonce          AJAX nonce for the create-draft endpoint.
 * @property {Function} closeModal     Callback to close the modal.
 * @property {Function} onRefresh      Callback to refresh the posts list.
 */
interface ImportModalProps {
	sourcePostId: number;
	title: string;
	sourceLink: string;
	postType: string;
	isUpdate: boolean;
	isLive: boolean;
	skippedCount?: number;
	ajaxurl: string;
	nonce: string;
	closeModal?: () => void;
	onRefresh?: () => void;
}

/**
 * Runs or confirms the import of a single post.
 *
 * @param {ImportModalProps} props Component props.
 */
const ImportModal = ( {
	sourcePostId,
	title,
	sourceLink,
	postType,
	isUpdate,
	isLive,
	skippedCount = 0,
	ajaxurl,
	nonce,
	closeModal,
	onRefresh,
}: ImportModalProps ) => {
	const { isLoading, error, editUrl, warnings, alreadyImported, submit } =
		useImportPost( {
			sourcePostId,
			title,
			sourceLink,
			postType,
			isUpdate,
			ajaxurl,
			nonce,
		} );

	// The ref keeps a re-render from starting a second import; the state drives
	// the refresh, which a ref cannot because it does not re-render.
	const hasStartedRef = useRef( false );
	const closeButtonRef = useRef< HTMLButtonElement >( null );
	const [ hasStarted, setHasStarted ] = useState( false );

	const start = (): void => {
		hasStartedRef.current = true;
		setHasStarted( true );
		submit();
	};

	useEffect( () => {
		if ( isLive || hasStartedRef.current ) {
			return;
		}
		start();
		// eslint-disable-next-line react-hooks/exhaustive-deps -- the ref, not the deps, makes this fire once.
	}, [ isLive ] );

	// A started import can have written even if it failed, so any dismiss
	// needs a refresh.
	useRefreshOnUnmount( hasStarted, onRefresh );

	// A live run keeps its action row mounted; every other stage swaps it out.
	useEffect( () => {
		if (
			null !== editUrl ||
			( ! isLive && ( isLoading || null !== error || alreadyImported ) )
		) {
			closeButtonRef.current?.focus();
		}
	}, [ alreadyImported, editUrl, error, isLive, isLoading ] );

	const loadingLabel = isUpdate ? __( 'Updating…', 'safe-publish' ) : __( 'Importing…', 'safe-publish' );

	// Present tense: The same notice shows before, during, and after the import.
	const skippedNotice = skippedCount > 0 && (
		<Text style={ { color: 'var(--safe-publish-status-warning)' } }>
			{ sprintf(
				/* translators: %d is the number of skipped posts */
				_n(
					'%d selected post is already up to date or cannot be imported, so it is not included.',
					'%d selected posts are already up to date or cannot be imported, so they are not included.',
					skippedCount,
					'safe-publish'
				),
				skippedCount
			) }
		</Text>
	);

	if ( editUrl ) {
		const successMessage = isUpdate
			? sprintf( /* translators: %s is the post title */
				__( '"%s" has been updated.', 'safe-publish' ), title
			)
			: sprintf( /* translators: %s is the post title */
				__( '"%s" has been imported as a draft.', 'safe-publish' ), title
			);

		return (
			<VStack spacing="5">
				{ skippedNotice }
				<Text role="status">{ successMessage }</Text>
				{ warnings.length > 0 && (
					<VStack spacing="2" className="safe-publish-import-warnings" role="status">
						{ warnings.map( ( warning, index ) => (
							<Text key={ index } className="safe-publish-import-warning">
								{ renderWarningMessage( warning ) }
							</Text>
						) ) }
					</VStack>
				) }
				<HStack justify="right">
					<Button
						__next40pxDefaultSize
						variant="tertiary"
						onClick={ closeModal }
						ref={ closeButtonRef }
					>
						{ __( 'Close', 'safe-publish' ) }
					</Button>
					<Button
						__next40pxDefaultSize
						variant="primary"
						onClick={ () => {
							window.open( editUrl, '_blank', 'noreferrer' );
							closeModal?.();
						} }
					>
						{ __( 'Edit post', 'safe-publish' ) }
					</Button>
				</HStack>
			</VStack>
		);
	}

	// The button label carries the warning: It is the last thing read before
	// the overwrite. Focus lands on Cancel, above which nothing is announced,
	// so the button also describes itself with the warning.
	if ( isLive ) {
		return (
			<VStack spacing="5">
				<Text id={ LIVE_HEADING_ID }>{ sprintf( /* translators: %s is the post title */
					__( '"%s" is live — this update publishes immediately', 'safe-publish' ),
					title
				) }</Text>
				<Text id={ LIVE_BODY_ID }>
					{ __(
						'Importing overwrites the published content with the source version immediately. A rollback restores the previous content.',
						'safe-publish'
					) }
				</Text>
				{ skippedNotice }
				{ error && (
					<Text role="alert" style={ { color: 'var(--safe-publish-status-error)' } }>
						<IsolatedErrorMessage error={ error } />
					</Text>
				) }
				<HStack justify="right">
					<Button
						__next40pxDefaultSize
						variant="tertiary"
						onClick={ closeModal }
						disabled={ isLoading }
						accessibleWhenDisabled
						ref={ closeButtonRef }
					>
						{ __( 'Cancel', 'safe-publish' ) }
					</Button>
					<Button
						__next40pxDefaultSize
						variant="primary"
						isDestructive
						onClick={ start }
						disabled={ isLoading }
						accessibleWhenDisabled
						aria-describedby={ `${ LIVE_HEADING_ID } ${ LIVE_BODY_ID }` }
					>
						{ isLoading ? (
							<>
								<Spinner />
								{ loadingLabel }
							</>
						) : __( 'Overwrite live post', 'safe-publish' ) }
					</Button>
				</HStack>
			</VStack>
		);
	}

	// A stale listing row can offer Import for a post that is already imported;
	// refreshing resolves it, so there is nothing to retry.
	const failureMessage = alreadyImported
		? __(
			'This post is already imported. Refresh the listing to see its current state.',
			'safe-publish'
		)
		: error;

	if ( null !== failureMessage ) {
		return (
			<VStack spacing="5">
				{ skippedNotice }
				<Text role="alert" style={ { color: 'var(--safe-publish-status-error)' } }>
					<IsolatedErrorMessage error={ failureMessage } />
				</Text>
				<HStack justify="right">
					<Button
						__next40pxDefaultSize
						variant="tertiary"
						onClick={ closeModal }
						ref={ closeButtonRef }
					>
						{ __( 'Close', 'safe-publish' ) }
					</Button>
					{ ! alreadyImported && (
						<Button
							__next40pxDefaultSize
							variant="primary"
							onClick={ start }
						>
							{ __( 'Retry', 'safe-publish' ) }
						</Button>
					) }
				</HStack>
			</VStack>
		);
	}

	// Close stays enabled: The hidden modal header leaves no dismiss affordance,
	// and nothing tabbable would strand keyboard focus outside the modal. It
	// dismisses without aborting the request.
	return (
		<VStack spacing="5">
			{ skippedNotice }
			<HStack justify="flex-start" role="status">
				<Spinner />
				<Text>{ loadingLabel }</Text>
			</HStack>
			<HStack justify="right">
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ closeModal }
					ref={ closeButtonRef }
				>
					{ __( 'Close', 'safe-publish' ) }
				</Button>
			</HStack>
		</VStack>
	);
};

export default ImportModal;
