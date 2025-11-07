import type { DataViews, filterSortAndPaginate } from '@wordpress/dataviews/wp';

declare global {
	var LockedPrivateDataViews: {
		filterSortAndPaginate: typeof filterSortAndPaginate;
		DataViews: typeof DataViews;
	};

	interface Window {
		ccpAdminData: {
			ajaxurl: string;
			nonce: string;
			siteUrl: string;
			numPosts: number;
			containerId: string;
			postsData: string;
			strings: Record<string, string>;
		};
		ccpRefreshPosts?: ( postType?: string ) => void;
	}
}

export {};
