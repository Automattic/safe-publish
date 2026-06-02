/**
 * Tests for constants
 */
import { describe, expect, it } from 'vitest';
import {
	LAYOUT_TABLE,
	SORTING_DIRECTIONS,
	sortArrows,
	sortValues,
	sortLabels,
	sortIcons,
} from '@/constants';

describe( 'Layout constants', () => {
	it( 'should define layout types', () => {
		expect( LAYOUT_TABLE ).toBe( 'table' );
	} );
} );

describe( 'Sorting constants', () => {
	it( 'should define sorting directions', () => {
		expect( SORTING_DIRECTIONS ).toEqual( [ 'asc', 'desc' ] );
	} );

	it( 'should define sort arrows', () => {
		expect( sortArrows.asc ).toBe( '↑' );
		expect( sortArrows.desc ).toBe( '↓' );
	} );

	it( 'should define sort values', () => {
		expect( sortValues.asc ).toBe( 'ascending' );
		expect( sortValues.desc ).toBe( 'descending' );
	} );

	it( 'should define sort labels', () => {
		expect( typeof sortLabels.asc ).toBe( 'string' );
		expect( typeof sortLabels.desc ).toBe( 'string' );
	} );

	it( 'should define sort icons', () => {
		expect( sortIcons.asc ).toBeDefined();
		expect( sortIcons.desc ).toBeDefined();
	} );
} );
