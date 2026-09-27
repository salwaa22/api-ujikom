<?php

namespace App\Http\Controllers;

use App\Models\Peminjaman;
use App\Models\Pengembalian;
use App\Models\Alat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PetugasController extends Controller
{
    // Menampilkan daftar pengajuan peminjaman dari siswa/peminjam
    public function indexPeminjaman(Request $request)
    {
        $search = $request->input('search');

        $peminjaman = Peminjaman::with(['user', 'detailPinjam.alat'])
            ->where('status', 'diajukan')
            ->when($search, function ($query, $search) {
                return $query->whereHas('user', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->get();

        return view('petugas.peminjaman.index', compact('peminjaman', 'search'));
    }

    public function setujuiPeminjaman($id)
    {
        DB::beginTransaction();

        try {
            $peminjaman = Peminjaman::with('detailPinjam')->findOrFail($id);
            $peminjaman->update(['status' => 'dipinjam']);

            // Kurangi stok alat secara otomatis
            foreach ($peminjaman->detailPinjam as $detail) {
                $alat = Alat::findOrFail($detail->alat_id);
                $alat->stok -= $detail->jumlah;
                $alat->save();
            }

            DB::commit();
            return redirect()->back()->with('success', 'Peminjaman disetujui dan stok alat dikurangi.');
        } catch (\Exception $e) {
            DB::rollback();
            return redirect()->back()->with('error', 'Terjadi kesalahan: ' . $e->getMessage());
        }
    }

    

    public function indexPengembalian()
    {
        // Mengambil data pengembalian / peminjaman yang relevan
        $pengembalian = Peminjaman::with(['user', 'detailPinjam.alat', 'pengembalian'])->latest()->get();
    
        // Kirim baik sebagai $pengembalian maupun $peminjaman agar view tidak error
        return view('petugas.pengembalian.index', compact('pengembalian'));
    }

    public function tolakPeminjaman($id)
    {
        $peminjaman = Peminjaman::findOrFail($id);

        if ($peminjaman->status !== 'diajukan') {
            return redirect()->back()->with('error', 'Peminjaman ini tidak dapat ditolak.');
        }
        
        $peminjaman->delete();

        return redirect()->back()->with('success', 'Peminjaman berhasil ditolak.');
    }

    public function createPengembalian($id)
    {
        $peminjaman = Peminjaman::with([
            'user',
            'detailPinjam.alat'
        ])->findOrFail($id);

        return view('petugas.pengembalian.create', compact('peminjaman'));
    }

    public function prosesPengembalian(Request $request, $peminjamanId)
    {
        $request->validate([
            'kondisi_kembali' => 'required|string',
            'denda' => 'nullable|integer|min:0',
        ]);

        DB::beginTransaction();

        try {
            $peminjaman = Peminjaman::with('detailPinjam')->findOrFail($peminjamanId);

            // Hitung keterlambatan
            $tanggalRencana = \Carbon\Carbon::parse($peminjaman->tgl_kembali_plan);
            $tanggalSekarang = \Carbon\Carbon::today();

            if ($tanggalSekarang->gt($tanggalRencana)) {
                $hariTerlambat = $tanggalRencana->diffInDays($tanggalSekarang);
            } else {
                $hariTerlambat = 0;
            }

            // Denda keterlambatan Rp5.000 per hari
            $dendaPerHari = 5000;
            $dendaKeterlambatan = $hariTerlambat * $dendaPerHari;

            // Denda kerusakan diisi manual dari form
            $dendaKerusakan = $request->denda_kerusakan ?? 0;

            // Total denda
            $totalDenda = $dendaKeterlambatan + $dendaKerusakan;

            // Simpan data pengembalian
            Pengembalian::create([
                'peminjaman_id' => $peminjaman->id,
                'tgl_kembali' => now(),
                'kondisi_kembali' => $request->kondisi_kembali,
                'denda' => $totalDenda,
                'petugas_id' => auth()->id(),
            ]);

            // Update status peminjaman jadi selesai
            $peminjaman->update(['status' => 'dikembalikan']);

            // Kembalikan stok alat ke inventaris
            foreach ($peminjaman->detailPinjam as $detail) {
                $alat = Alat::findOrFail($detail->alat_id);
                $alat->stok += $detail->jumlah;
                $alat->save();
            }

            DB::commit();
            return redirect()->route('petugas.pengembalian.index')->with('success', 'Pengembalian berhasil dicatat dan stok dipulihkan.');
        } catch (\Exception $e) {
            DB::rollback();
            return redirect()->back()->with('error', 'Terjadi kesalahan: ' . $e->getMessage());
        }
    }


    public function indexLaporan()
    {
        // Mengambil data peminjaman beserta relasi user dan detail alurnya untuk laporan
        $peminjaman = \App\Models\Peminjaman::with(['user', 'detailPinjam.alat'])->latest()->get();

        return view('petugas.laporan.index', compact('peminjaman'));
    }
}