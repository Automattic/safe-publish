export interface DiffPreviewPayload {
	postId: number;
	postType?: string;
	content?: string;
	mode?: 'split' | 'inline';
	cleanup?: boolean;
}

export interface BlockDiff {
	index: number;
	status: 'unchanged' | 'added' | 'removed' | 'modified';
	current?: {
		name?: string;
		attrs?: Record< string, any >;
		rendered: string;
	} | null;
	incoming?: {
		name?: string;
		attrs?: Record< string, any >;
		rendered: string;
	} | null;
}

export interface DiffPreviewResult {
	contentDiffHtml?: string;
	renderedContentDiffHtml?: string;
	nonContentDiffs?: {
		title?: string;
		excerpt?: string;
		taxonomies?: string;
		meta?: string;
		featuredMedia?: string;
	};
	blockDiffs?: BlockDiff[]; // NEW
	error?: string;
	localPostId?: number;
	incoming?: {
		title?: string;
		excerpt?: string;
		meta?: Record< string, any >;
		terms?: Record< string, string[] >;
	};
	current?: {
		title?: string;
		excerpt?: string;
		meta?: Record< string, any >;
		terms?: Record< string, string[] >;
	};
	html?: string;
	incomingRenderedHtml?: string;
	currentRenderedHtml?: string;
}

export async function fetchDiffPreview(
	payload: DiffPreviewPayload
): Promise< DiffPreviewResult > {
	const res = await fetch( '/wp-json/ccp/v1/diff-preview', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify( payload ),
	} );
	if ( ! res.ok ) {
		const text = await res.text().catch( () => 'Failed to fetch diff' );
		return { error: text };
	}
	return res.json();
}

export interface UpdatePostResult {
	success: boolean;
	error?: string;
}

export async function updatePostContent(
	postId: number,
	content: string,
	nonce?: string,
	meta?: Record< string, any >,
	terms?: Record< string, string[] >,
	title?: string,
	excerpt?: string,
	featuredMediaId?: number
): Promise< UpdatePostResult > {
	const headers: Record< string, string > = { 'Content-Type': 'application/json' };
	const wpNonce = nonce || ( window as any )?.ccpAdminData?.restNonce;
	if ( wpNonce ) {
		headers[ 'X-WP-Nonce' ] = wpNonce;
	}

	const body: Record< string, any > = {
		postId,
		content,
		...( typeof title !== 'undefined' ? { title } : {} ),
		...( typeof excerpt !== 'undefined' ? { excerpt } : {} ),
		...( meta ? { meta } : {} ),
		...( terms ? { terms } : {} ),
		...( typeof featuredMediaId !== 'undefined' ? { featuredMediaId } : {} ),
	};

	const res = await fetch( '/wp-json/ccp/v1/update-post', {
		method: 'POST',
		headers,
		body: JSON.stringify( body ),
	} );

	if ( ! res.ok ) {
		const text = await res.text().catch( () => '' );
		return { success: false, error: text || `HTTP ${ res.status }` };
	}

	const data = await res.json().catch( () => null );

	if ( data && data.success === true ) {
		return { success: true };
	}

	return { success: false, error: data && data.error ? data.error : 'Invalid response' };
}
