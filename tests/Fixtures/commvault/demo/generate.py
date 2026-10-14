#!/usr/bin/env python3
"""Generates the demo / test fixtures in the exact JSON shapes returned by the
KBR CommServe (11.40), see docs/commvault-mapping.md section 10.
Reference "now": 14/10/2026 10:00 Europe/Brussels. Run: python3 generate.py"""
import json, os
from datetime import datetime
from zoneinfo import ZoneInfo

BXL = ZoneInfo("Europe/Brussels")
HERE = os.path.dirname(os.path.abspath(__file__))

def ts(local):  # "2026-05-09 20:00" Brussels -> epoch
    return int(datetime.strptime(local, "%Y-%m-%d %H:%M").replace(tzinfo=BXL).timestamp())

def dump(name, data):
    with open(os.path.join(HERE, name), "w") as f:
        json.dump(data, f, indent=2, ensure_ascii=False)
        f.write("\n")

LIB = 36
POL = {138: "SP_VSP_Online-N", 139: "SP_VSP_Online-S", 132: "SP_VSP_Stock"}
COPY = {167: (138, "Master"), 168: (139, "Master"), 161: (132, "Master")}
SCRATCH = {"id": 230, "name": "Default Scratch"}
LTO9 = {"id": 236, "name": "LTO9"}
mid = [6900]

def media(barcode, status, exported, copy=None, slot=None, drive=None, last_write=None, retain=None, size=0, group=LTO9):
    mid[0] += 1
    pol = COPY[copy][0] if copy else 0
    return {
        "libraryName": "", "spareGroupType": 1 if group == SCRATCH else 2, "libraryId": LIB,
        "storedDataBytes": size, "mediaId": mid[0],
        "lastWriteTime": ts(last_write) if last_write else 0,
        "retainDataUntil": ts(retain) if retain else 0,
        "isInMediaGroup": status != "Spare Media", "isExported": exported, "guiMediaAttribute": 2,
        "barcode": barcode, "status": status,
        "container": {"name": "", "id": 0},
        "storagePolicy": {"name": POL.get(pol, ""), "id": pol},
        "mediaType": {"name": "ULTRIUM Cleaning Tape" if barcode.startswith("CLN") else "ULTRIUM V9", "id": 160 if barcode.startswith("CLN") else 2011},
        "slot": {"name": f"slot {slot}" if slot else "", "id": 369368 + slot if slot else 0},
        "storagePolicyCopy": {"name": COPY[copy][1] if copy else "", "id": copy or 0},
        "spareGroup": {"name": "Cleaning Media", "id": 235} if barcode.startswith("CLN") else group,
        "drive": {"name": drive or "", "id": 101 if drive else 0},
    }

TB = 18_000_000_000_000
inside = [
    # Online-N, current backup (Full 284001, 12/09/2026, retained until 12/06/2027)
    media("K00120L8", "Full Media", False, 167, slot=5, last_write="2026-09-20 06:12", retain="2027-06-12 20:41", size=TB),
    media("K00121L8", "Full Media", False, 167, slot=6, last_write="2026-10-02 23:05", retain="2027-06-12 20:41", size=TB),
    media("K00122L8", "Active Media", False, 167, slot=7, last_write="2026-10-13 22:10", retain="2027-06-12 20:41", size=TB // 3),
    # Online-S, Full 284500 running now on drive 1
    media("K00150L8", "Active Media", False, 168, slot=22, drive="IBM ULT3580-HH9_1", last_write="2026-10-14 09:55", retain="2027-07-13 22:03", size=TB // 2),
    media("K00151L8", "Full Media", False, 168, slot=23, last_write="2026-10-14 03:00", retain="2027-07-13 22:03", size=TB),
    # Stock: partially written tape, no job running
    media("K00161L8", "Appendable Media", False, 161, slot=41, last_write="2026-07-06 04:30", retain="2027-07-04 21:00", size=TB // 4),
    # aged tape already back in the library, scratch, cleaning, bad
    media("K00047L8", "Aged Media", False, 167, slot=44, last_write="2025-12-02 10:00"),
    *[media(f"K0017{i}L8", "Spare Media", False, slot=45 + i, group=SCRATCH) for i in range(5)],
    media("K00099L8", "Bad Media", False, 167, slot=50, last_write="2026-06-01 12:00", retain="2027-02-08 20:15", size=1000),
    media("CLN001CU", "Cleaning Media", False, slot=60),
]
outside = [
    # Online-N: the real KBR sheet, shifted by 6 months (Full 09/05/2026, retained until 08/02/2027)
    *[media(b, "Full Media", True, 167, last_write=lw, retain="2027-02-08 20:15", size=TB) for b, lw in [
        ("K00067L8", "2026-05-27 18:40"), ("K00057L8", "2026-06-09 11:57"), ("K00087L8", "2026-06-10 00:33"),
        ("K00034L8", "2026-06-10 17:02"), ("K00086L8", "2026-07-07 20:13"), ("K00088L8", "2026-07-22 09:18"),
        ("K00100L8", "2026-08-07 03:59")]],
    # Online-S previous backup
    media("K00140L8", "Full Media", True, 168, last_write="2026-04-20 05:00", retain="2027-01-10 21:30", size=TB),
    media("K00141L8", "Full Media", True, 168, last_write="2026-05-02 01:00", retain="2027-01-10 21:30", size=TB),
    # Stock
    media("K00160L8", "Full Media", True, 161, last_write="2026-07-05 14:00", retain="2027-07-04 21:00", size=TB),
    # aged outside: to bring back
    media("K00032L8", "Aged Media", True, 167, last_write="2025-08-20 10:00"),
    media("K00035L8", "Aged Media", True, 167, last_write="2025-09-02 10:00"),
    media("K00036L8", "Aged Media", True, 168, last_write="2025-09-10 10:00"),
]

dump("GET_V4_Storage_Tape.json", {"tapeStorage": [
    {"id": 26, "name": "IBM INFRA", "storageType": "TAPE", "status": "Ready", "manufacturer": "IBM", "model": "3573-TL", "company": {"id": 0, "name": ""}},
    {"id": 36, "name": "IBM VSP", "storageType": "TAPE", "status": "Ready", "manufacturer": "IBM", "model": "3573-TL", "company": {"id": 0, "name": ""}},
]})
drives = [{"driveName": f"IBM ULT3580-HH9_{i}", "driveId": 100 + i, "driveType": "ULTRIUM-V9", "status": "Ready",
           "mountStatus": "Media Mounted" if i == 1 else "Drive Empty",
           "barcode": "K00150L8" if i == 1 else "", "mediaId": inside[3]["mediaId"] if i == 1 else 0,
           "jobIds": "284500" if i == 1 else ""} for i in range(1, 5)]
dump("GET_Library_36.json", {"libraryInfo": {
    "library": {"libraryName": "IBM VSP", "libraryId": 36}, "model": "3573-TL", "libraryType": 1,
    "tapeLibSummary": {"numOfRegSlots": 60, "numOfRegSlotOccupied": len(inside), "numOfMedia": len(inside) + len(outside), "isOnline": "Yes"},
    "DriveList": drives}})
dump("GET_V4_Library_36_Media__filterMediaType-ALL_isExported-false.json", {"mediaDetailsList": inside})
dump("GET_V4_Library_36_Media__filterMediaType-ALL_isExported-true.json", {"mediaDetailsList": outside})

def policy(pid, copies):
    return {"storagePolicy": {"storagePolicyName": POL[pid], "storagePolicyId": pid}, "copy": copies}

def copy_entry(cid, name, default, prec, lib, lib_name, days, cycles):
    return {"copyType": 1 if default else 2, "active": 1, "isDefault": int(default), "isSnapCopy": 0, "copyPrecedence": prec,
            "storageType": 1, "retentionRules": {"retainBackupDataForCycles": cycles, "retainBackupDataForDays": days, "jobs": 0},
            "StoragePolicyCopy": {"copyId": cid, "copyName": name},
            "library": {"libraryName": lib_name, "libraryId": lib}}

dump("GET_StoragePolicy_138.json", policy(138, [copy_entry(166, "Primary", True, 1, 7, "KBR_DL01", 30, 1), copy_entry(167, "Master", False, 2, 36, "IBM VSP", 270, 1)]))
dump("GET_StoragePolicy_139.json", policy(139, [copy_entry(169, "Primary", True, 1, 7, "KBR_DL01", 30, 1), copy_entry(168, "Master", False, 2, 36, "IBM VSP", 270, 1)]))
dump("GET_StoragePolicy_132.json", policy(132, [copy_entry(160, "Primary", True, 1, 7, "KBR_DL01", 30, 1), copy_entry(161, "Master", False, 2, 36, "IBM VSP", 365, 1)]))

jid = [0]
def job(job_id, jtype, start, end, retain, client=118, sub="default", status="Available"):
    return {"jobId": job_id, "jobType": jtype, "status": status, "statusId": 1, "isAged": False,
            "startTime": ts(start), "endTime": ts(end) if end else 0, "retainUntil": ts(retain),
            "server": {"name": f"srv-{client}", "id": client}, "subClient": {"name": sub, "id": 266},
            "backupSet": {"name": "defaultBackupSet", "id": 149}, "retentionReason": "BASIC_DAYS,BASIC_CYCLES"}

dump("GET_V4_Plan_BackupDestination_167_Jobs.json", {"jobs": [
    job(279001, "Full", "2026-05-09 20:00", "2026-08-07 04:10", "2027-02-08 20:00"),
    job(284001, "Full", "2026-09-12 20:00", "2026-10-13 22:30", "2027-06-12 20:00"),
]})
dump("GET_V4_Plan_BackupDestination_168_Jobs.json", {"jobs": [
    job(270100, "Full", "2026-04-11 21:00", "2026-05-02 02:00", "2027-01-10 21:00", client=119),
]})
dump("GET_V4_Plan_BackupDestination_161_Jobs.json", {"jobs": [
    job(281000, "Full", "2026-07-04 20:30", "2026-07-06 04:40", "2027-07-04 20:30", client=120),
]})
dump("GET_Job.json", {"totalRecordsWithoutPaging": 1, "jobs": [{"jobSummary": {
    "jobId": 284500, "status": "Running", "backupLevelName": "Full", "jobStartTime": ts("2026-10-13 22:00"),
    "storagePolicy": {"storagePolicyName": "SP_VSP_Online-S", "storagePolicyId": 139},
    "subclient": {"clientId": 119, "clientName": "srv-119", "subclientName": "default"}}}]})
print("fixtures written to", HERE)
