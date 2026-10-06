<?php
use ApSemplice\Xlsx;
use PHPUnit\Framework\TestCase;

final class XlsxTest extends TestCase {

	private function files( string $sheet_xml, array $extra = array() ): array {
		$ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
		return array_merge(
			array(
				'xl/workbook.xml'            => '<?xml version="1.0"?><workbook ' . $ns . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
					. '<sheet name="Soci" sheetId="1" r:id="rId1"/><sheet name="Nascosto" sheetId="2" state="hidden" r:id="rId2"/></sheets></workbook>',
				'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
					. '<Relationship Id="rId1" Type="x" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="x" Target="/xl/worksheets/sheet2.xml"/></Relationships>',
				'xl/sharedStrings.xml'       => '<?xml version="1.0"?><sst ' . $ns . '><si><t>Nome</t></si><si><t>Cognome</t></si><si><r><t>Ros</t></r><r><t>si</t></r></si><si><t>Mario</t></si></sst>',
				'xl/styles.xml'              => '<?xml version="1.0"?><styleSheet ' . $ns . '><numFmts count="2"><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/><numFmt numFmtId="165" formatCode="#,##0.00 &quot;€&quot;"/></numFmts>'
					. '<cellXfs count="4"><xf numFmtId="0"/><xf numFmtId="14"/><xf numFmtId="164"/><xf numFmtId="165"/></cellXfs></styleSheet>',
				'xl/worksheets/sheet1.xml'   => '<?xml version="1.0"?><worksheet ' . $ns . '><sheetData>' . $sheet_xml . '</sheetData></worksheet>',
				'xl/worksheets/sheet2.xml'   => '<?xml version="1.0"?><worksheet ' . $ns . '><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>segreto</t></is></c></row></sheetData></worksheet>',
			),
			$extra
		);
	}

	public function test_reads_strings_numbers_dates_and_keeps_row_numbers(): void {
		$rows = '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="inlineStr"><is><t>Data</t></is></c><c r="D1" t="inlineStr"><is><t>Importo</t></is></c></row>'
			. '<row r="3"><c r="A3" t="s"><v>3</v></c><c r="B3" t="s"><v>2</v></c><c r="C3" s="1"><v>45306</v></c><c r="D3" s="3"><v>12.5</v></c></row>'
			. '<row r="4"><c r="A4"/></row>'
			. '<row r="6"><c r="B6" t="str"><f>A1</f><v>formula</v></c><c r="D6" t="b"><v>1</v></c><c r="E6" s="2"><v>45306.75</v></c></row>';
		$sheets = Xlsx::parse( $this->files( $rows ) );
		$this->assertCount( 1, $sheets, 'il foglio nascosto non si legge' );
		$this->assertSame( 'Soci', $sheets[0]['name'] );
		$this->assertSame( array( 1, 3, 6 ), $sheets[0]['lines'], 'numeri di riga di Excel, senza le righe vuote' );
		$this->assertSame( array( 'Nome', 'Cognome', 'Data', 'Importo' ), $sheets[0]['rows'][0] );
		$this->assertSame( array( 'Mario', 'Rossi', '2024-01-15', '12.5' ), $sheets[0]['rows'][1], 'testo con formattazione, data e numero' );
		$this->assertSame( array( '', 'formula', '', '1', '2024-01-15' ), $sheets[0]['rows'][2], 'celle saltate, formula, booleano, data personalizzata' );
	}

	public function test_serial_dates(): void {
		$this->assertSame( '2024-01-15', Xlsx::serial_to_date( 45306 ) );
		$this->assertSame( '1900-01-01', Xlsx::serial_to_date( 1 ) );
		$this->assertSame( '1900-03-01', Xlsx::serial_to_date( 61 ) );
		$this->assertSame( '2024-01-15', Xlsx::serial_to_date( 45306.99 ) );
		$this->assertSame( '2024-01-15', Xlsx::serial_to_date( 43844, true ), 'sistema 1904' );
		$this->assertNull( Xlsx::serial_to_date( 0 ) );
	}

	public function test_date_formats_and_columns(): void {
		$this->assertTrue( Xlsx::is_date_format( 'dd/mm/yyyy' ) );
		$this->assertTrue( Xlsx::is_date_format( '[$-410]d mmmm yyyy;@' ) );
		$this->assertFalse( Xlsx::is_date_format( '#,##0.00' ) );
		$this->assertFalse( Xlsx::is_date_format( '0.00 "€"' ) );
		$this->assertFalse( Xlsx::is_date_format( 'General' ) );
		$this->assertSame( 0, Xlsx::column_index( 'A1' ) );
		$this->assertSame( 25, Xlsx::column_index( 'Z9' ) );
		$this->assertSame( 26, Xlsx::column_index( 'AA2' ) );
		$this->assertSame( 54, Xlsx::column_index( 'BC12' ) );
	}

	public function test_rejects_invalid_and_dangerous_files(): void {
		$this->expectException( \InvalidArgumentException::class );
		Xlsx::parse( array( 'foo' => 'bar' ) );
	}

	public function test_rejects_entities(): void {
		$files = $this->files( '', array( 'xl/sharedStrings.xml' => '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "b">]><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>&a;</t></si></sst>' ) );
		$this->expectException( \InvalidArgumentException::class );
		Xlsx::parse( $files );
	}

	public function test_scientific_numbers_and_prefixed_namespace(): void {
		$ns   = 'xmlns:x="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
		$rows = '<x:row r="1"><x:c r="A1"><x:v>1.5E+3</x:v></x:c><x:c r="B1"><x:v>0.1</x:v></x:c></x:row>';
		$f    = $this->files( '', array( 'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><x:worksheet ' . $ns . '><x:sheetData>' . $rows . '</x:sheetData></x:worksheet>' ) );
		$s    = Xlsx::parse( $f );
		$this->assertSame( array( array( '1500', '0.1' ) ), $s[0]['rows'] );
	}
}
