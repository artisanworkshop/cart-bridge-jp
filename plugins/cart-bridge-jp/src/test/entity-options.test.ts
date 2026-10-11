import { afterEach, describe, expect, it } from '@jest/globals';
import { entityLabel, entityLabels } from '../entity-labels';
import {
	defaultExportSelection,
	exportEntityOptions,
	exportOptionHelp,
	importEntityOptions,
	importOptionHelp,
	writableOptions,
} from '../entity-options';
import type { Connection } from '../types';

function connection( entities: unknown ): Connection {
	return { entities } as unknown as Connection;
}

describe( 'importEntityOptions', () => {
	it( 'keeps the server order and reads the notice flag', () => {
		expect(
			importEntityOptions(
				connection( {
					import: [
						{
							key: 'category',
							label: 'Categories',
							mapping_notice: false,
							dry_run_only: false,
							dry_run_only_reason: '',
						},
						{
							key: 'gizmo',
							label: 'Gizmos',
							mapping_notice: true,
							dry_run_only: false,
							dry_run_only_reason: '',
						},
						{
							key: 'order',
							label: 'Orders',
							mapping_notice: true,
							dry_run_only: true,
							dry_run_only_reason: 'Activate the add-on.',
						},
					],
					export: [],
				} )
			)
		).toEqual( [
			{
				key: 'category',
				label: 'Categories',
				mapping_notice: false,
				dry_run_only: false,
				dry_run_only_reason: '',
			},
			{
				key: 'gizmo',
				label: 'Gizmos',
				mapping_notice: true,
				dry_run_only: false,
				dry_run_only_reason: '',
			},
			{
				key: 'order',
				label: 'Orders',
				mapping_notice: true,
				dry_run_only: true,
				dry_run_only_reason: 'Activate the add-on.',
			},
		] );
	} );

	it( 'drops malformed and duplicate options and reads only true as true', () => {
		expect(
			importEntityOptions(
				connection( {
					import: [
						null,
						{ label: 'No key' },
						{ key: '' },
						{ key: 5 },
						{ key: 'tag', mapping_notice: 'true' },
						{ key: 'tag', label: 'Tags again' },
					],
				} )
			)
		).toEqual( [
			// 読めない `dry_run_only` は dry-run だけ（実際の取込みで送らない側）に倒す（R3-8b）。
			{
				key: 'tag',
				label: 'tag',
				mapping_notice: false,
				dry_run_only: true,
				dry_run_only_reason: '',
			},
		] );
	} );

	it( 'returns nothing for a missing connection or declaration', () => {
		expect( importEntityOptions( null ) ).toEqual( [] );
		expect( importEntityOptions( connection( undefined ) ) ).toEqual( [] );
		expect( importEntityOptions( connection( { import: 'x' } ) ) ).toEqual(
			[]
		);
	} );
} );

describe( 'exportEntityOptions', () => {
	it( 'reads beta and the description, and treats an unreadable beta as beta', () => {
		const options = exportEntityOptions(
			connection( {
				export: [
					{
						key: 'product',
						label: 'Products',
						beta: false,
						description: '',
						dry_run_only: false,
						dry_run_only_reason: '',
					},
					{
						key: 'order',
						label: 'Orders',
						beta: true,
						description: 'Creates orders.',
						dry_run_only: false,
						dry_run_only_reason: 3,
					},
					// 読めない `beta`・`dry_run_only` は既定で選ばない・実際のエクスポートで送らない側に倒す（原則 9・R3-8b）。
					{
						key: 'gizmo',
						label: 'Gizmos',
						description: 7,
						dry_run_only: 'false',
					},
				],
			} )
		);

		expect( options ).toEqual( [
			{
				key: 'product',
				label: 'Products',
				beta: false,
				description: '',
				dry_run_only: false,
				dry_run_only_reason: '',
			},
			{
				key: 'order',
				label: 'Orders',
				beta: true,
				description: 'Creates orders.',
				dry_run_only: false,
				dry_run_only_reason: '',
			},
			{
				key: 'gizmo',
				label: 'Gizmos',
				beta: true,
				description: '',
				dry_run_only: true,
				dry_run_only_reason: '',
			},
		] );
		expect( defaultExportSelection( options ) ).toEqual( [ 'product' ] );
	} );
} );

describe( 'exportOptionHelp', () => {
	const option = {
		key: 'order',
		label: 'Orders',
		beta: false,
		description: '',
		dry_run_only: false,
		dry_run_only_reason: '',
	};

	it( 'shows the description, followed by the beta note for a beta option', () => {
		expect(
			exportOptionHelp(
				{ ...option, beta: true, description: 'Creates orders.' },
				'Beta note.',
				'Preview only.'
			)
		).toBe( 'Creates orders. Beta note.' );
		expect(
			exportOptionHelp(
				{ ...option, beta: true },
				'Beta note.',
				'Preview only.'
			)
		).toBe( 'Beta note.' );
		// ベータでない種類も、説明があれば出す（受注のエクスポートをベータにしない接続先。R3-6b2 R1-L1）。
		expect(
			exportOptionHelp(
				{ ...option, description: 'Creates orders.' },
				'Beta note.',
				'Preview only.'
			)
		).toBe( 'Creates orders.' );
	} );

	it( 'adds the preview-only note and the reason for a dry-run-only option (R3-8b)', () => {
		expect(
			exportOptionHelp(
				{
					...option,
					beta: true,
					description: 'Creates orders.',
					dry_run_only: true,
					dry_run_only_reason: 'Activate the add-on.',
				},
				'Beta note.',
				'Preview only.'
			)
		).toBe(
			'Creates orders. Beta note. Preview only. Activate the add-on.'
		);
		expect(
			exportOptionHelp(
				{ ...option, dry_run_only: true },
				'Beta note.',
				'Preview only.'
			)
		).toBe( 'Preview only.' );
		// 理由だけ持っていても、dry-run だけでなければ出さない。
		expect(
			exportOptionHelp(
				{ ...option, dry_run_only_reason: 'Activate the add-on.' },
				'Beta note.',
				'Preview only.'
			)
		).toBeUndefined();
	} );

	it( 'shows nothing for a non-beta option without a description', () => {
		expect(
			exportOptionHelp( option, 'Beta note.', 'Preview only.' )
		).toBeUndefined();
	} );
} );

describe( 'importOptionHelp', () => {
	const option = {
		key: 'customer',
		label: 'Customers',
		mapping_notice: false,
		dry_run_only: false,
		dry_run_only_reason: '',
	};

	it( 'shows nothing unless the option is dry-run only (nothing new without an add-on)', () => {
		expect( importOptionHelp( option, 'Preview only.' ) ).toBeUndefined();
	} );

	it( 'shows the preview-only note followed by the reason', () => {
		expect(
			importOptionHelp(
				{
					...option,
					dry_run_only: true,
					dry_run_only_reason: 'Activate the add-on.',
				},
				'Preview only.'
			)
		).toBe( 'Preview only. Activate the add-on.' );
		expect(
			importOptionHelp(
				{ ...option, dry_run_only: true },
				'Preview only.'
			)
		).toBe( 'Preview only.' );
	} );
} );

describe( 'writableOptions', () => {
	const options = [
		{ key: 'category', dry_run_only: false },
		{ key: 'product', dry_run_only: false },
		{ key: 'customer', dry_run_only: true },
		{ key: 'order', dry_run_only: true },
	];

	it( 'keeps the selected options in run order and drops the dry-run-only ones (R3-8b)', () => {
		expect(
			writableOptions(
				options,
				new Set( [ 'order', 'product', 'customer', 'category' ] )
			).map( ( option ) => option.key )
		).toEqual( [ 'category', 'product' ] );
		expect(
			writableOptions( options, new Set( [ 'product' ] ) ).map(
				( option ) => option.key
			)
		).toEqual( [ 'product' ] );
	} );

	it( 'is empty when only dry-run-only options (or nothing) are selected', () => {
		expect(
			writableOptions( options, new Set( [ 'customer', 'order' ] ) )
		).toEqual( [] );
		expect( writableOptions( options, new Set() ) ).toEqual( [] );
	} );

	it( 'keeps every selected option when nothing is dry-run only (no add-on)', () => {
		const free = options.map( ( option ) => ( {
			...option,
			dry_run_only: false,
		} ) );

		expect(
			writableOptions(
				free,
				new Set( [ 'category', 'product', 'customer', 'order' ] )
			)
		).toEqual( free );
	} );
} );

describe( 'entityLabels', () => {
	afterEach( () => {
		delete window.cbjpAdmin;
	} );

	it( 'reads the labels the server passed and falls back to the key', () => {
		window.cbjpAdmin = {
			restUrl: '',
			restNonce: '',
			entityLabels: {
				product: 'Products',
				variant: 'Variations',
				bad: 3,
			},
		};

		expect( entityLabel( 'product' ) ).toBe( 'Products' );
		expect( entityLabel( 'variant' ) ).toBe( 'Variations' );
		expect( entityLabel( 'bad' ) ).toBe( 'bad' );
		expect( entityLabel( 'gizmo' ) ).toBe( 'gizmo' );
		// 継承プロパティを名前として拾わない。
		expect( entityLabel( 'constructor' ) ).toBe( 'constructor' );
	} );

	it( 'survives a missing or malformed value', () => {
		expect( entityLabels() ).toEqual( {} );

		window.cbjpAdmin = {
			restUrl: '',
			restNonce: '',
			entityLabels: [ 'x' ],
		};
		expect( entityLabel( '0' ) ).toBe( '0' );
	} );
} );
