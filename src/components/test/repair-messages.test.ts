import { describe, expect, it } from '@jest/globals';
import { repairResultMessage, scanResultMessage } from '../repair-messages';

describe( 'scanResultMessage', () => {
	it( 'says nothing needs repair when everything is fine', () => {
		expect( scanResultMessage( false, 0, 0 ) ).toBe(
			'No records need repair.'
		);
	} );

	it( 'reports only the records to repair', () => {
		expect( scanResultMessage( true, 3, 0 ) ).toBe(
			'3 records need repair. Review the numbers below, then click “Repair”.'
		);
	} );

	it( 'uses the singular form for one record', () => {
		expect( scanResultMessage( true, 1, 0 ) ).toBe(
			'1 record needs repair. Review the numbers below, then click “Repair”.'
		);
	} );

	it( 'reports only the unresolved records when nothing needs repair', () => {
		expect( scanResultMessage( false, 0, 2 ) ).toBe(
			'2 records could not be confirmed or checked and will be left as they are. See the numbers below.'
		);
	} );

	it( 'joins both sentences when there are records to repair and unresolved ones', () => {
		expect( scanResultMessage( true, 3, 1 ) ).toBe(
			'3 records need repair. Review the numbers below, then click “Repair”. 1 record could not be confirmed or checked and will be left as it is. See the numbers below.'
		);
	} );
} );

describe( 'repairResultMessage', () => {
	it( 'reports the corrected records and asks for another scan', () => {
		expect( repairResultMessage( 2, 0 ) ).toBe(
			'Repair finished. 2 records were corrected. Run “Scan” again to confirm that nothing is left.'
		);
	} );

	it( 'adds the unresolved records between the two sentences', () => {
		expect( repairResultMessage( 1, 4 ) ).toBe(
			'Repair finished. 1 record was corrected. 4 records could not be confirmed or checked and were left unchanged. Run “Scan” again to confirm that nothing is left.'
		);
	} );
} );
