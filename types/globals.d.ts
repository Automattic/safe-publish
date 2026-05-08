import type { DataViews, filterSortAndPaginate } from '@wordpress/dataviews/wp';

declare global {
	var LockedPrivateDataViews: {
		filterSortAndPaginate: typeof filterSortAndPaginate;
		DataViews: typeof DataViews;
	};

	interface Window {
		safePublishAdminData: {
			ajaxurl: string;
			nonce: string;
			sourceSiteUrl: string;
			numPosts: number;
			containerId: string;
			postsData: string;
			strings: Record< string, string >;
		};
	}
}

export {};
