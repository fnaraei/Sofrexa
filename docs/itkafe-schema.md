# ItKafe database — schema map

Reference for the migration. The legacy system is the source; nothing here is kept in Sofrexa as-is.

## The database

| | |
|---|---|
| Source | `K:\ITK\lab\database\11-6-2025` (SQL Server backup, no file extension) |
| Original name | `basilic` on `DESKTOP-JNVMEQG\SQLEXPRESS`, backed up 2026-06-11 |
| Engine | SQL Server 2008 R2, compatibility level 100 |
| Collation | `Cyrillic_General_CI_AS` — the program is Russian, but the Basilic data is almost all English (see the notes) |
| Order data | order lines from **2019-04-30 to 2025-11-24** — nothing after that, although the backup is dated 2026-06-11 |
| Size | 1.09 GB |
| Restored locally as | `ItKafe` on `localhost\SQLEXPRESS` (SQL Server 2019 Express) |

Restore command, if it has to be repeated:

```sql
RESTORE DATABASE [ItKafe] FROM DISK = N'K:\ITK\lab\database\11-6-2025'
WITH MOVE N'Sklad_dat' TO N'...\MSSQL\DATA\ItKafe.mdf',
     MOVE N'Sklad_log' TO N'...\MSSQL\DATA\ItKafe_log.ldf', RECOVERY;
```

**Contents:** 209 tables (99 hold data), 101 views, 184 stored procedures, 6,077,673 rows in total.

Naming follows the Russian original: `R_` = restaurant floor, `S_` = reference data and stock, `Z_` = staff and payroll, `KB`/`ForKA` = cash and fiscal, `Link*`/`Log*` = internal plumbing.

Order lines per year (`R_Doc_Down`):

| Year | Lines |
|---|---:|
| 2019 | 42,531 |
| 2020 | 68,459 |
| 2021 | 61,108 |
| 2022 | 78,951 |
| 2023 | 82,888 |
| 2024 | 88,827 |
| 2025 | 64,241 |

2019 starts on 30 April and 2025 stops on 24 November, so neither is a full year.

## Tables that matter

### Orders and service

The live restaurant: open orders, order lines and their state machine.

#### `R_Doc_Info` — 157,117 rows, 13 columns

*Primary key:* `idZakaz`

| Column | Type | Null |
|---|---|---|
| `idZakaz` | int | no |
| `DocNote` | nvarchar(500) | yes |
| `DataCreate` | datetime | no |
| `KolMan` | int | no |
| `KolWoman` | int | no |
| `KolChildren` | int | no |
| `Dat_Key` | int | no |
| `Pass` | int | no |
| `FlFiltrUp` | int | no |
| `phoneOnline` | nvarchar(50) | yes |
| `sendToAroma` | bit | no |
| `sendAdrOnline` | bit | no |
| `idAromaUser` | int | no |

*Referenced by:* `R_Doc_Down.idZakaz`

#### `R_Doc_Down` — 487,005 rows, 44 columns

*Primary key:* `R_Down_Key`

| Column | Type | Null |
|---|---|---|
| `R_Down_Key` | int | no |
| `Mn_Key` | int | no |
| `R_Down_Create` | datetime | no |
| `D_Down_Kol` | numeric | no |
| `Fl_Prinyto` | bit | no |
| `Fl_Sdelano` | bit | no |
| `Fl_Vidano` | bit | no |
| `Fl_Oplacheno` | bit | no |
| `D_Down_Sdelano` | datetime | yes |
| `D_Down_Vidano` | datetime | yes |
| `D_Down_Oplacheno` | datetime | yes |
| `Key_JobCreate` | int | no |
| `Key_JobSdelano` | int | yes |
| `Key_JobVidano` | int | yes |
| `Key_JobOplacheno` | int | yes |
| `Kl_Key` | int | no |
| `St_Key` | int | no |
| `St_KeyGroup` | int | no |
| `D_Down_Note` | nvarchar(255) | no |
| `Fl_begin` | bit | no |
| `CenOld` | money | no |
| `S_Key` | int | no |
| `D_Down_Skid` | numeric | no |
| `D_Down_Obsl` | numeric | no |
| `D_Dat` | int | no |
| `D_Ves` | numeric | no |
| `Tmp1` | bit | no |
| `Print_S4et` | int | no |
| `idZakaz` | int | no |
| `VidAkc` | int | no |
| `Fl_Trouble` | bit | no |
| `Podarok` | bit | no |
| `MyCena` | decimal | no |
| `MySkid` | decimal | no |
| `MyObsl` | decimal | no |
| `MySumma` | decimal | no |
| `MyCenaVOS` | decimal | no |
| `ControlerTime` | int | no |
| `MyFlBonus` | bit | no |
| `MyBonus` | decimal | no |
| `FlFiltr` | int | no |
| `AddSkidka` | numeric | no |
| `keyArh` | int | no |
| `S_KeySpis` | int | no |

*References:* `idZakaz` → `R_Doc_Info.idZakaz`, `Key_JobCreate` → `Z_WorkMansJob.Key_Job`, `Mn_Key` → `R_Menu.Mn_Key`, `St_Key` → `R_Stol.St_Key`, `S_Key` → `S_Sklad.S_Key`, `Kl_Key` → `S_Klient.Kl_Key`

#### `R_Doc_DownDel` — 28,640 rows, 45 columns

| Column | Type | Null |
|---|---|---|
| `RDownKeyTmp` | int | no |
| `RDownCreateTmp` | datetime | no |
| `RDownKeyDelTmp` | int | no |
| `R_Down_Key` | int | no |
| `Mn_Key` | int | no |
| `R_Down_Create` | datetime | no |
| `D_Down_Kol` | numeric | no |
| `Fl_Prinyto` | bit | no |
| `Fl_Sdelano` | bit | no |
| `Fl_Vidano` | bit | no |
| `Fl_Oplacheno` | bit | no |
| `D_Down_Sdelano` | datetime | yes |
| `D_Down_Vidano` | datetime | yes |
| `D_Down_Oplacheno` | datetime | yes |
| `Key_JobCreate` | int | no |
| `Key_JobSdelano` | int | yes |
| `Key_JobVidano` | int | yes |
| `Key_JobOplacheno` | int | yes |
| `Kl_Key` | int | no |
| `St_Key` | int | no |
| `St_KeyGroup` | int | no |
| `D_Down_Note` | nvarchar(255) | no |
| `Fl_begin` | bit | no |
| `CenOld` | money | no |
| `S_Key` | int | no |
| `D_Down_Skid` | numeric | no |
| `D_Down_Obsl` | numeric | no |
| `D_Dat` | int | no |
| `D_Ves` | numeric | no |
| `Tmp1` | bit | no |
| `Print_S4et` | int | no |
| `idZakaz` | int | no |
| `VidAkc` | int | no |
| `Fl_Trouble` | bit | no |
| `Podarok` | bit | no |
| `ControlerTime` | int | no |
| `MyCena` | decimal | no |
| `MySkid` | decimal | no |
| `MyObsl` | decimal | no |
| `MySumma` | decimal | no |
| `MyCenaVOS` | decimal | no |
| `MyFlBonus` | bit | no |
| `FlFiltr` | int | no |
| `AddSkidka` | numeric | no |
| `keyArh` | int | no |

#### `R_Stol` — 62 rows, 18 columns

*Primary key:* `St_Key`

| Column | Type | Null |
|---|---|---|
| `St_Key` | int | no |
| `St_Name` | nvarchar(50) | no |
| `St_Type` | int | no |
| `St_Color` | ntext | yes |
| `St_Full_Color` | ntext | yes |
| `St_Add` | nvarchar(50) | yes |
| `St_Select_Color` | ntext | yes |
| `St_Port` | int | no |
| `St_Net_Pass` | int | no |
| `St_Net_PassAll` | int | no |
| `St_Online` | bit | no |
| `St_PhoneOnLine` | nvarchar(50) | no |
| `St_TypeMenu` | int | no |
| `idZona` | int | no |
| `St_Active` | bit | no |
| `IsVisibleOnSite` | bit | no |
| `PosX` | float | no |
| `PosY` | float | no |

*References:* `St_Type` → `R_Stol_Type.St_Type`

*Referenced by:* `R_Doc_Down.St_Key`, `R_Predv_Stol.St_Key`

### Menu and recipes

What can be sold, how it is grouped and what it consumes.

#### `R_Menu` — 1,396 rows, 65 columns

*Primary key:* `Mn_Key`

| Column | Type | Null |
|---|---|---|
| `Mn_Key` | int | no |
| `Mn_Name` | nvarchar(100) | no |
| `Key_Tree` | int | no |
| `Mn_Price1` | money | no |
| `Mn_Note` | ntext | yes |
| `Mn_SposobPrig1` | ntext | yes |
| `Mn_SposobPrig2` | ntext | yes |
| `Mn_PredvXran` | ntext | yes |
| `Mn_Ukrash` | ntext | yes |
| `Mn_Foto` | image | yes |
| `Mn_Foto_Format` | varchar(50) | yes |
| `Mn_Min_Koef` | numeric | no |
| `Mn_Max_Koef` | numeric | no |
| `Print_Key` | int | yes |
| `Mn_Sort` | int | no |
| `Mn_Print_Pod_Razd` | nvarchar(250) | yes |
| `Mn_Name_KPK` | nvarchar(100) | no |
| `Mn_Print_Vihod` | numeric | no |
| `Mn_Ves` | nvarchar(250) | yes |
| `Mn_Ves_R1` | nvarchar(3) | yes |
| `Mn_Ves_R2` | nvarchar(3) | yes |
| `Mn_Ves_Default` | int | no |
| `Mn_Play_File` | nvarchar(50) | yes |
| `Virt_Key` | int | no |
| `Mn_Price2` | money | no |
| `Mn_CenRazn` | bit | no |
| `Mn_Predv_No_Obsl` | bit | no |
| `Mn_Print_VihodBut` | numeric | no |
| `Mn_ForKA` | int | no |
| `Mn_ForKAOtdel` | int | no |
| `Mn_ForKAGroup` | int | no |
| `Mn_ForKAComm` | nvarchar(50) | no |
| `Mn_ForKATax` | int | no |
| `Mn_ForKAId` | int | no |
| `Mn_Lock` | bit | no |
| `Mn_StopList` | int | no |
| `Mn_FiltrSkid` | nvarchar(50) | yes |
| `Mn_NotZP` | bit | no |
| `Mn_Stolb1` | nvarchar(50) | yes |
| `Mn_Stolb2` | nvarchar(50) | yes |
| `Mn_ShtrixKod` | nvarchar(50) | no |
| `Mn_Minus` | bit | no |
| `Mn_NotS4et` | bit | no |
| `Mn_Bonus` | money | no |
| `Mn_BonusFlProc` | bit | no |
| `Mn_FlTime` | int | no |
| `Mn_DataCreate` | datetime | no |
| `Mn_Razdel` | int | no |
| `KeyMn1C` | char(9) | no |
| `Key_A_Key` | int | no |
| `Fl_Tmp` | int | no |
| `Fl_ClearSkidka` | bit | no |
| `MDataUpdate` | datetime | no |
| `MVerUpdate` | int | no |
| `ZipName` | nvarchar(10) | no |
| `ZipKol` | numeric | no |
| `Mn_CurSebest` | money | no |
| `Kod_Vesi` | int | no |
| `Mn_uktz` | nvarchar(50) | no |
| `Mn_excize_stamp` | nvarchar(50) | no |
| `FILTRNUMKA` | int | no |
| `IsWeight` | bit | no |
| `MinWeight` | decimal | no |
| `WeightStep` | decimal | no |
| `UUnit` | nvarchar | no |

*References:* `Key_Tree` → `S_TreeView.Key_Tree`

*Referenced by:* `R_Doc_Down.Mn_Key`, `R_Menu_Komplekt.Mn_Key`, `R_Per_Menu.Mn_Key`, `R_Predv_Down.Mn_Key`, `S_PerMenu.Mn_Key`

#### `R_Komplekt` — 1,078 rows, 5 columns

*Primary key:* `R_Kmpl_Key`

| Column | Type | Null |
|---|---|---|
| `R_Kmpl_Key` | int | no |
| `R_Kmpl_Name` | nvarchar(250) | no |
| `Key_Tree` | int | no |
| `R_Kmpl_Fl_Ukr` | bit | no |
| `FlTKNotOpenK` | bit | no |

*References:* `Key_Tree` → `S_TreeView.Key_Tree`

*Referenced by:* `R_Komplekt_R_Assort.R_Kmpl_Key`, `R_Menu_Komplekt.R_Kmpl_Key`, `R_Per_Kompl.R_Kmpl_Key`, `S_PerKomplekt.R_Kmpl_Key`

#### `R_Menu_Komplekt` — 2,719 rows, 4 columns

*Primary key:* `Mn_K_Key`

| Column | Type | Null |
|---|---|---|
| `Mn_K_Key` | int | no |
| `R_Kmpl_Key` | int | no |
| `Mn_Key` | int | no |
| `Mn_K_Koef` | numeric | no |

*References:* `Mn_Key` → `R_Menu.Mn_Key`, `R_Kmpl_Key` → `R_Komplekt.R_Kmpl_Key`

#### `R_Komplekt_R_Assort` — 3,819 rows, 6 columns

*Primary key:* `P_K_Key`

| Column | Type | Null |
|---|---|---|
| `P_K_Key` | int | no |
| `R_Ass_Key` | int | no |
| `R_Kmpl_Key` | int | no |
| `P_K_Koef` | numeric | no |
| `P_K_Spos_Nareski` | nvarchar(250) | yes |
| `P_K_Predv_Podg` | nvarchar(250) | yes |

*References:* `R_Ass_Key` → `R_Assort.R_Ass_Key`, `R_Kmpl_Key` → `R_Komplekt.R_Kmpl_Key`

#### `R_Assort` — 863 rows, 6 columns

*Primary key:* `R_Ass_Key`

| Column | Type | Null |
|---|---|---|
| `R_Ass_Key` | int | no |
| `R_Ass_Name` | nvarchar(250) | no |
| `Key_Tree` | int | no |
| `LastEditDate` | datetime | yes |
| `CreationDate` | datetime | yes |
| `FlTKNotOpen` | bit | no |

*Referenced by:* `R_Assort_Koef.R_Ass_Key`, `R_Komplekt_R_Assort.R_Ass_Key`, `R_Per_R_Ass.R_Ass_Key`, `S_PerPoluf.R_Ass_Key`

#### `R_MenuCurCalc` — 8,814 rows, 4 columns

*Primary key:* `Mn_Key`, `A_Key`

| Column | Type | Null |
|---|---|---|
| `Mn_Key` | int | no |
| `A_Key` | int | no |
| `KolA_Key` | numeric | no |
| `SebA_Key` | money | no |

### Cash, payments and fiscal

Money in and out, shift totals and the fiscal-printer queue.

#### `OplataDocument` — 104,037 rows, 9 columns

*Primary key:* `Oplkey`

| Column | Type | Null |
|---|---|---|
| `Oplkey` | int | no |
| `Kl_Key` | int | no |
| `VidTab` | int | no |
| `KeyTab` | int | no |
| `VidKey` | int | no |
| `OplSum` | money | no |
| `OplPolu4eno` | money | no |
| `DopPar` | int | no |
| `FromTable` | int | no |

#### `R_Kassa_Of` — 80,789 rows, 11 columns

*Primary key:* `RKas_Key`

| Column | Type | Null |
|---|---|---|
| `RKas_Key` | int | no |
| `RKas_SumP` | money | no |
| `RKas_SumR` | money | no |
| `Note` | nvarchar(250) | yes |
| `Key_Job` | int | no |
| `Dat` | int | no |
| `Kod` | int | no |
| `RKas_Data_Create` | datetime | no |
| `TmpR_Down_Key` | int | no |
| `Vid_Opl` | int | no |
| `S_Key` | int | no |

*References:* `Dat` → `Z_Smena_History.Dat`, `Key_Job` → `Z_Smena_History.Key_Job`, `Kod` → `KBCelNaznPlat.Kod`

#### `KBOper` — 78,617 rows, 19 columns

*Primary key:* `Key_Op`

| Column | Type | Null |
|---|---|---|
| `Key_Op` | int | no |
| `DocNum` | int | yes |
| `DocData` | datetime | yes |
| `Key_KBOrg` | int | no |
| `SummaPr` | money | no |
| `SummaRas` | money | no |
| `SummaNDS` | money | no |
| `Kl_Key` | int | no |
| `Osnovan` | varchar(255) | yes |
| `Dop` | varchar(255) | yes |
| `DataCreate` | datetime | no |
| `KeyRS` | int | yes |
| `Key_Acc` | varchar(8) | no |
| `Kod` | int | no |
| `Opl_Sklad` | money | no |
| `No_People` | int | no |
| `Tmp` | int | yes |
| `Dop_Progr` | int | no |
| `Key_Op2` | int | no |

*References:* `Kod` → `KBCelNaznPlat.Kod`, `Key_KBOrg` → `KBOrg.Key_KBOrg`, `Kl_Key` → `S_Klient.Kl_Key`

*Referenced by:* `S_KB_Oplata.Key_Op`

#### `S_KB_Oplata` — 18,951 rows, 7 columns

*Primary key:* `Opl_Key`

| Column | Type | Null |
|---|---|---|
| `Opl_Key` | int | no |
| `Opl_Sum` | money | no |
| `Opl_KursUE` | decimal | no |
| `DU_Key` | int | no |
| `Key_Op` | int | no |
| `Opl_Data_Create` | datetime | no |
| `Opl_Note` | varchar(255) | yes |

*References:* `DU_Key` → `S_Doc_Up.DU_Key`, `Key_Op` → `KBOper.Key_Op`

#### `ForKAClose` — 104,730 rows, 12 columns

*Primary key:* `Close_Key`

| Column | Type | Null |
|---|---|---|
| `Close_Key` | int | no |
| `DataCreate` | datetime | no |
| `NoteClose` | nvarchar(255) | no |
| `FlPrintKA` | bit | no |
| `FlBack` | bit | no |
| `RunPrint` | bit | no |
| `RunFrom` | int | no |
| `IdZakaz` | int | no |
| `KAAdress` | nvarchar(100) | no |
| `Razdel` | int | no |
| `keyKA` | int | no |
| `keyKAForPrint` | int | no |

*Referenced by:* `ForKACloseOplata.Close_Key`, `ForKACloseParam.Close_Key`, `ForKACloseS4etStr.Close_Key`, `ForKACloseStroka.Close_Key`

#### `ForKACloseOplata` — 104,037 rows, 6 columns

*Primary key:* `KAOpl_Key`

| Column | Type | Null |
|---|---|---|
| `KAOpl_Key` | int | no |
| `Close_Key` | int | no |
| `Summa` | money | no |
| `Sda4a` | money | no |
| `VidKey` | int | no |
| `VidToKA` | int | no |

*References:* `Close_Key` → `ForKAClose.Close_Key`

#### `ForKACloseParam` — 1,816,352 rows, 4 columns

*Primary key:* `Par_Key`

| Column | Type | Null |
|---|---|---|
| `Par_Key` | int | no |
| `Close_Key` | int | no |
| `MyKey` | nvarchar(250) | no |
| `MyValue` | nvarchar(250) | no |

*References:* `Close_Key` → `ForKAClose.Close_Key`

#### `ForKACloseS4etStr` — 411,208 rows, 29 columns

*Primary key:* `keySrtArh`

| Column | Type | Null |
|---|---|---|
| `keySrtArh` | int | no |
| `Close_Key` | int | no |
| `Virt_Name` | nvarchar(50) | no |
| `DatePrint` | datetime | no |
| `IdZakaz` | int | no |
| `St_Name` | nvarchar(50) | no |
| `St_KeyGroup` | int | no |
| `Mn_Name2` | nvarchar(100) | no |
| `NName` | nvarchar(50) | no |
| `Kl_Name` | varchar(250) | no |
| `KlBalans` | money | no |
| `CenaPrice_BezObslBezSkid` | money | no |
| `KolVo` | numeric | no |
| `MyObsl` | money | no |
| `MySkid` | money | no |
| `MyBonus` | money | no |
| `MySumma` | money | no |
| `D_Pred` | money | no |
| `valuta` | nvarchar(5) | no |
| `D_Down_Note` | nvarchar(255) | no |
| `S_Name` | varchar(250) | no |
| `Start` | datetime | no |
| `Ost_Op_Predv` | int | no |
| `KolMan` | int | no |
| `KolWoman` | int | no |
| `KolChildren` | int | no |
| `Mn_Key` | int | no |
| `DocNote` | nvarchar(255) | no |
| `K_Diskont` | nvarchar(50) | no |

*References:* `Close_Key` → `ForKAClose.Close_Key`

#### `Stat_DayOplata` — 26,860 rows, 7 columns

*Primary key:* `dayId`, `VidKey`, `NoPeople`, `S_Key`

| Column | Type | Null |
|---|---|---|
| `dayId` | int | no |
| `VidKey` | int | no |
| `NoPeople` | int | no |
| `KeyJob` | int | no |
| `SumKOplata` | money | no |
| `SumNaStole` | money | no |
| `S_Key` | int | no |

### Customers

Customer accounts, balances and turnover.

#### `S_Klient` — 960 rows, 38 columns

*Primary key:* `Kl_Key`

| Column | Type | Null |
|---|---|---|
| `Kl_Key` | int | no |
| `Kl_Name` | varchar(250) | no |
| `Key_Tree` | int | no |
| `K_Note` | varchar(250) | yes |
| `K_ZKPO` | varchar(15) | yes |
| `K_INN` | varchar(12) | yes |
| `K_Adress` | varchar(255) | yes |
| `K_Svid` | varchar(15) | yes |
| `K_Emale` | varchar(20) | yes |
| `K_Subkonto` | varchar(50) | yes |
| `DataCreate` | datetime | yes |
| `K_Foto` | image | yes |
| `K_Foto_Format` | varchar(50) | yes |
| `K_Diskont` | varchar(50) | no |
| `K_Phone` | varchar(50) | yes |
| `K_DR` | datetime | yes |
| `K_DR_FL` | bit | no |
| `K_Cen_Default` | int | no |
| `Fl_Org` | bit | no |
| `K_Phone1` | varchar(50) | yes |
| `K_Phone2` | varchar(50) | yes |
| `K_KreditNol` | money | no |
| `K_DatSbros` | datetime | no |
| `K_FlBonus` | bit | no |
| `Kl_Bonus` | int | no |
| `K_VisBonusInS4et` | bit | no |
| `NetName` | nvarchar(50) | no |
| `NetPass` | nvarchar(50) | no |
| `NetLastRestorePass` | datetime | no |
| `NetInvayt` | int | no |
| `NetInvaytTree` | int | no |
| `KeyKl1C` | char(9) | no |
| `Kl_NameShort` | nvarchar(150) | no |
| `VidPrice` | int | no |
| `F_Fl_Not_In_Find` | bit | no |
| `K_Skidka` | decimal | no |
| `idAroma` | int | no |
| `notCallDiscont` | bit | no |

*References:* `Key_Tree` → `S_TreeView.Key_Tree`

*Referenced by:* `KBOper.Kl_Key`, `R_Doc_Down.Kl_Key`, `R_Predv.Kl_Key`, `R_ProizvUp.Kl_Key`, `S_Doc_Up.Kl_Key`, `S_Klient_Adress.Kl_Key`, `S_Klient_RS.Kl_Key`, `S_Klient_Sotr.Kl_Key`, `TmpS_Doc_Up.Kl_Key`, `Z_WorkMans.Kl_Key`

#### `S_KlientOborot` — 14,208 rows, 9 columns

*Primary key:* `Key_Oborot`

| Column | Type | Null |
|---|---|---|
| `Key_Oborot` | int | no |
| `Kl_Key` | int | no |
| `SumObor` | money | no |
| `NoteObor` | nvarchar(250) | yes |
| `Razdel` | int | no |
| `DataCreate` | datetime | no |
| `DataObor` | datetime | no |
| `Key_JobCreate` | int | no |
| `FlSms` | bit | no |

### Stock and suppliers

Goods receipts, write-offs and stock documents.

#### `S_Doc_Up` — 20,156 rows, 32 columns

*Primary key:* `DU_Key`

| Column | Type | Null |
|---|---|---|
| `DU_Key` | int | no |
| `Kl_Key` | int | no |
| `S_Key1` | int | no |
| `S_Key2` | int | no |
| `Key_Type` | int | no |
| `DU_Nomer1` | int | no |
| `DU_Nomer2` | int | no |
| `DU_Nomer3` | int | no |
| `DU_Nomer4` | int | no |
| `DU_Nomer5` | int | no |
| `DU_Nomer6` | int | no |
| `DU_Add` | varchar(50) | yes |
| `DU_Dat1` | datetime | yes |
| `DU_Dat2` | datetime | yes |
| `DU_Dat3` | datetime | yes |
| `DU_Dat4` | datetime | yes |
| `DU_Dat5` | datetime | yes |
| `DU_Dat6` | datetime | yes |
| `DU_Primech` | varchar(250) | yes |
| `Data_Create` | datetime | no |
| `DU_Skidka` | int | no |
| `DU_KursUE` | money | no |
| `Val_Key` | int | no |
| `DU_Otsrochka` | datetime | no |
| `DU_Sum` | money | no |
| `DU_Oplata` | money | no |
| `DU_Form_Raschet` | int | no |
| `DU_Setup` | ntext | yes |
| `Key_Job` | int | no |
| `TypeBuch` | int | no |
| `TypeLock` | int | no |
| `NotInOldPer` | bit | no |

*References:* `Val_Key` → `Valuta.Val_Key`, `Kl_Key` → `S_Klient.Kl_Key`, `Key_Type` → `S_Type_Doc.Key_Type`, `S_Key1` → `S_Sklad.S_Key`, `S_Key2` → `S_Sklad.S_Key`

*Referenced by:* `S_Doc_Down.DU_Key`, `S_KB_Oplata.DU_Key`

#### `S_Doc_Down` — 46,011 rows, 15 columns

*Primary key:* `DD_Key`

| Column | Type | Null |
|---|---|---|
| `DD_Key` | int | no |
| `DU_Key` | int | no |
| `A_Key` | int | no |
| `DD_Kol` | decimal | no |
| `DD_Ost` | decimal | no |
| `DD_CenSNDS` | money | no |
| `DD_CenBNDS` | money | no |
| `DD_NDS` | int | no |
| `DD_Key_Ref` | int | no |
| `DD_Primech` | varchar(250) | yes |
| `DD_A_Price1` | money | no |
| `DD_Real` | money | no |
| `DD_Skidka` | int | no |
| `DD_Koef` | real | no |
| `DD_Tmp1` | bit | no |

*References:* `A_Key` → `S_Assort.A_Key`, `DU_Key` → `S_Doc_Up.DU_Key`

#### `S_Sklad` — 1 rows, 4 columns

*Primary key:* `S_Key`

| Column | Type | Null |
|---|---|---|
| `S_Key` | int | no |
| `S_Name` | varchar(250) | no |
| `Key_KBOrg` | int | yes |
| `VarCalc` | int | no |

*Referenced by:* `R_Doc_Down.S_Key`, `R_NewPer.S_Key`, `R_Per_Kompl.S_Key`, `R_Per_Menu.S_Key`, `R_Per_R_Ass.S_Key`, `R_Predv.S_Key`, `R_S4et4ik.S_Key`, `S_Doc_Up.S_Key1`, `S_Doc_Up.S_Key2`, `S_Per.S_Key`, `S_Skl_Ass.S_Key`, `TmpS_Doc_Up.S_Key1`, `TmpS_Doc_Up.S_Key2`

### Staff, shifts and payroll

Who worked when, and what they were paid.

#### `Z_WorkMans` — 50 rows, 50 columns

*Primary key:* `No_People`

| Column | Type | Null |
|---|---|---|
| `Key_Div` | int | no |
| `Kl_Key` | int | no |
| `No_People` | int | no |
| `W_Name1` | nvarchar(200) | yes |
| `W_Name2` | nvarchar(50) | yes |
| `W_Name3` | nvarchar(50) | yes |
| `Key_Naym` | int | yes |
| `W_Address` | nvarchar(100) | yes |
| `W_BirthDay` | datetime | yes |
| `W_Men_Or_Girl` | bit | no |
| `W_Kol_Childr` | int | yes |
| `W_Id_Code` | nvarchar(10) | yes |
| `W_Last_Job` | nvarchar(100) | yes |
| `W_Note` | nvarchar(255) | yes |
| `W_State_Brak` | bit | no |
| `W_Passport` | nvarchar(200) | yes |
| `W_Fl_Uvolen` | bit | no |
| `W_DateNayma` | datetime | yes |
| `W_DateUvolen` | datetime | yes |
| `W_TablNo` | int | yes |
| `W_Inval` | bit | yes |
| `W_DataCreate` | datetime | yes |
| `W_Obrazovan` | int | yes |
| `W_Foto1` | image | yes |
| `W_Foto2` | image | yes |
| `W_Foto3` | image | yes |
| `W_Foto4` | image | yes |
| `W_Foto5` | image | yes |
| `W_Foto_Format1` | nvarchar(50) | yes |
| `W_Foto_Format2` | nvarchar(50) | yes |
| `W_Foto_Format3` | nvarchar(50) | yes |
| `W_Foto_Format4` | nvarchar(50) | yes |
| `W_Foto_Format5` | nvarchar(50) | yes |
| `W_Phone` | nchar(255) | yes |
| `Tmp_Key_User` | int | yes |
| `Tmp_Key_Kl` | int | yes |
| `W_Access` | ntext | yes |
| `W_Nick` | nvarchar(50) | yes |
| `W_DataZPStart` | datetime | no |
| `Admin` | bit | no |
| `W_Color` | ntext | yes |
| `Key_JobNew` | int | no |
| `Key_DolgnostNew` | int | no |
| `Job_StavkaNew` | money | no |
| `Job_Fl_TarifNew` | int | no |
| `Job_Kol_HoursNew` | real | no |
| `Job_Razd_TerminalNew` | int | no |
| `Job_Vid_AutoKorrNew` | int | no |
| `Klas_Pov_KoefNew` | real | no |
| `J_StolNew` | int | no |

*References:* `Kl_Key` → `S_Klient.Kl_Key`, `Key_Naym` → `ZZ_Vid_Nayma.Key_Naym`, `Key_Div` → `ZZ_Division.Key_Div`

*Referenced by:* `AccessPeople.No_People`, `Z_WorkMansJob.No_People`

#### `Z_Smena_History` — 26,605 rows, 13 columns

*Primary key:* `Key_Job`, `Dat`

| Column | Type | Null |
|---|---|---|
| `Key_Hist` | int | no |
| `Key_Job` | int | no |
| `Dat` | int | no |
| `Dat_Start` | datetime | no |
| `Dat_End` | datetime | yes |
| `Note` | text | yes |
| `Dop_Setting` | ntext | yes |
| `Key_Reg_His` | int | no |
| `Block` | bit | no |
| `Norma_Start` | datetime | no |
| `Norma_End` | datetime | no |
| `Norma_Z` | real | no |
| `Min_Otrab` | int | no |

*References:* `Key_Reg_His` → `Z_Smena_Regim_History.Key_Reg_His`, `Key_Job` → `Z_WorkMansJob.Key_Job`

*Referenced by:* `R_Kassa_Of.Dat`, `R_Kassa_Of.Key_Job`

#### `Z_Viplata_History` — 27,014 rows, 14 columns

*Primary key:* `HV_Key`

| Column | Type | Null |
|---|---|---|
| `HV_Key` | int | no |
| `No_People` | int | no |
| `Key_Job` | int | no |
| `Key_Table` | int | no |
| `Name_Table` | nvarchar(20) | yes |
| `HV_Data` | datetime | no |
| `HV_Dat` | int | no |
| `HV_Date_Create` | datetime | no |
| `HV_Summa` | money | no |
| `HV_Summa_Korr` | money | no |
| `HV_Note` | nvarchar(250) | yes |
| `HV_Dop` | int | no |
| `Data_Create` | datetime | no |
| `Name_Create` | nvarchar(50) | yes |

#### `AccessPeople` — 23 rows, 3 columns

*Primary key:* `Key_Acc_People`

| Column | Type | Null |
|---|---|---|
| `Key_Acc_People` | int | no |
| `KeyAccess` | int | no |
| `No_People` | int | no |

*References:* `KeyAccess` → `Access.KeyAccess`, `No_People` → `Z_WorkMans.No_People`

#### `Access` — 4 rows, 3 columns

*Primary key:* `KeyAccess`

| Column | Type | Null |
|---|---|---|
| `KeyAccess` | int | no |
| `AccessName` | nvarchar(100) | no |
| `AccessVal` | nvarchar(250) | no |

*Referenced by:* `AccessPeople.KeyAccess`

### System

Logging, printing, localisation and internal link tables.

#### `Log` — 80,438 rows, 8 columns

*Primary key:* `Key_Log`

| Column | Type | Null |
|---|---|---|
| `Key_Log` | int | no |
| `L_Comp` | varchar(50) | no |
| `L_User` | varchar(50) | no |
| `L_Table` | varchar(250) | no |
| `L_KeyTable` | int | no |
| `L_Note` | varchar(255) | yes |
| `Data_Create` | datetime | no |
| `Razd` | int | no |

#### `LinkTableStr` — 1,609,377 rows, 4 columns

*Primary key:* `KeyStrTable`

| Column | Type | Null |
|---|---|---|
| `KeyStrTable` | int | no |
| `KeyUpTable` | int | no |
| `TableKey` | int | no |
| `TableName` | nvarchar(25) | no |

*References:* `KeyUpTable` → `LinkTableUp.KeyUpTable`

#### `LinkTableUp` — 742,700 rows, 6 columns

*Primary key:* `KeyUpTable`

| Column | Type | Null |
|---|---|---|
| `KeyUpTable` | int | no |
| `Key_JobCreate` | int | no |
| `IdSmena` | int | no |
| `Undo` | bit | no |
| `DateCreate` | datetime | no |
| `CodeOp` | int | no |

*Referenced by:* `LinkTableLog.KeyUpTable`, `LinkTableStr.KeyUpTable`

#### `LinkTableLog` — 4,577 rows, 7 columns

*Primary key:* `KeyLinkLog`

| Column | Type | Null |
|---|---|---|
| `KeyLinkLog` | int | no |
| `DataCreate` | datetime | no |
| `KeyUpTable` | int | no |
| `Key_Job` | int | no |
| `OpSum` | money | no |
| `OpNote` | nvarchar(255) | yes |
| `OpAddPar` | nvarchar(255) | yes |

*References:* `KeyUpTable` → `LinkTableUp.KeyUpTable`

#### `R_Print_Error` — 21,501 rows, 7 columns

*Primary key:* `Key_Err`

| Column | Type | Null |
|---|---|---|
| `Key_Err` | int | no |
| `DataCreate` | datetime | no |
| `Message` | nvarchar(250) | no |
| `R_Stol` | int | no |
| `R_Gr` | int | no |
| `S_Key` | int | no |
| `OtherData` | ntext | yes |

#### `R_Print_Arch` — 1,611 rows, 14 columns

*Primary key:* `KeyPrintArch`

| Column | Type | Null |
|---|---|---|
| `KeyPrintArch` | int | no |
| `R_Stol` | int | no |
| `R_Gr` | int | no |
| `S_Key` | int | no |
| `Data` | ntext | no |
| `DataCreate` | datetime | no |
| `DataPrinting` | datetime | no |
| `SummaNetto` | money | no |
| `Key_Job` | int | no |
| `No_People` | int | no |
| `MessageOther` | nvarchar(250) | no |
| `SummaBrutto` | money | no |
| `Flag` | int | no |
| `IdZakaz` | int | no |

#### `Messages_Lng` — 1,964 rows, 8 columns

*Primary key:* `id_mess`, `id_lang`

| Column | Type | Null |
|---|---|---|
| `id_mess` | nvarchar(50) | no |
| `id_lang` | nchar(5) | no |
| `str_mess` | nvarchar(1000) | no |
| `fl_user` | bit | no |
| `l_adress` | nvarchar | no |
| `l_add` | int | no |
| `l_dataCreate` | datetime | no |
| `keyInt` | int | no |

#### `Log_Create_Menu` — 3,840 rows, 4 columns

*Primary key:* `Key_Upd`

| Column | Type | Null |
|---|---|---|
| `Key_Upd` | int | no |
| `Ttext` | nvarchar(250) | no |
| `Razd` | int | no |
| `DateLast` | datetime | no |

#### `R_NewPer` — 1,733 rows, 5 columns

*Primary key:* `R_Pereuchkey`

| Column | Type | Null |
|---|---|---|
| `R_Pereuchkey` | int | no |
| `PerDat` | datetime | no |
| `PerPrim` | nvarchar(250) | no |
| `S_Key` | int | no |
| `NotU4et` | bit | no |

*References:* `S_Key` → `S_Sklad.S_Key`

*Referenced by:* `R_NewPer_Kompl.R_Pereuchkey`, `R_NewPer_Menu.R_Pereuchkey`, `R_NewPer_R_Ass.R_Pereuchkey`, `R_NewPerStr.R_Pereuchkey`

#### `R_NewPerStr` — 30,132 rows, 11 columns

| Column | Type | Null |
|---|---|---|
| `Key_StrPer` | int | no |
| `R_Pereuchkey` | int | no |
| `A_Key` | int | no |
| `Per_OstA` | decimal | no |
| `Ost_Out` | decimal | no |
| `Ost_In` | decimal | no |
| `Out_Nedost` | decimal | no |
| `Date_In` | datetime | no |
| `Kol_In_Menu` | decimal | no |
| `Kol_In_Prod` | decimal | no |
| `Kol_In_Sklad` | decimal | no |

*References:* `A_Key` → `S_Assort.A_Key`, `R_Pereuchkey` → `R_NewPer.R_Pereuchkey`

## Every table, by row count

| Table | Rows | Columns |
|---|---:|---:|
| `ForKACloseParam` | 1,816,352 | 4 |
| `LinkTableStr` | 1,609,377 | 4 |
| `LinkTableUp` | 742,700 | 6 |
| `R_Doc_Down` | 487,005 | 44 |
| `ForKACloseS4etStr` | 411,208 | 29 |
| `R_Doc_Info` | 157,117 | 13 |
| `ForKAClose` | 104,730 | 12 |
| `ForKACloseOplata` | 104,037 | 6 |
| `OplataDocument` | 104,037 | 9 |
| `R_Kassa_Of` | 80,789 | 11 |
| `Log` | 80,438 | 8 |
| `KBOper` | 78,617 | 19 |
| `S_Doc_Down` | 46,011 | 15 |
| `R_NewPerStr` | 30,132 | 11 |
| `R_Doc_DownDel` | 28,640 | 45 |
| `Z_Viplata_History` | 27,014 | 14 |
| `Stat_DayOplata` | 26,860 | 7 |
| `Z_Smena_History` | 26,605 | 13 |
| `R_Print_Error` | 21,501 | 7 |
| `S_Doc_Up` | 20,156 | 32 |
| `S_KB_Oplata` | 18,951 | 7 |
| `S_KlientOborot` | 14,208 | 9 |
| `R_MenuCurCalc` | 8,814 | 4 |
| `LinkTableLog` | 4,577 | 7 |
| `Log_Create_Menu` | 3,840 | 4 |
| `R_Komplekt_R_Assort` | 3,819 | 6 |
| `R_Menu_Komplekt` | 2,719 | 4 |
| `R_Stat` | 2,272 | 7 |
| `Messages_Lng` | 1,964 | 8 |
| `R_NewPer` | 1,733 | 5 |
| `R_Print_Arch` | 1,611 | 14 |
| `R_Menu` | 1,396 | 65 |
| `R_Komplekt` | 1,078 | 5 |
| `S_Klient` | 960 | 38 |
| `R_Assort` | 863 | 6 |
| `R_Assort_Koef` | 842 | 4 |
| `S_Assort` | 757 | 61 |
| `S_Skl_Ass` | 757 | 14 |
| `R_Assort_Tombstone` | 550 | 2 |
| `R_Predv_Down` | 377 | 12 |
| `Log_File` | 351 | 6 |
| `S_O_TmpParam` | 326 | 7 |
| `S_TreeView` | 318 | 10 |
| `R_Akciya_Menu` | 266 | 3 |
| `Setting` | 209 | 5 |
| `S_O_Setting` | 107 | 8 |
| `R_Predv_TimeEtalon` | 96 | 3 |
| `R_NewPer_Kompl` | 68 | 4 |
| `R_Stol` | 62 | 18 |
| `Z_WorkMans` | 50 | 50 |
| `Z_WorkMansJob` | 50 | 12 |
| `R_Modif` | 46 | 2 |
| `KB_Kod_Klient` | 27 | 4 |
| `R_Predv` | 26 | 19 |
| `R_Predv_Stol` | 26 | 3 |
| `R_Print_Razd` | 24 | 6 |
| `AccessPeople` | 23 | 3 |
| `R_Akciya` | 16 | 33 |
| `R_Print_Terminal` | 11 | 9 |
| `T_Update` | 11 | 3 |
| `OplataVid` | 9 | 8 |
| `Log_CalcZar` | 8 | 2 |
| `R_Stol_Type` | 8 | 13 |
| `AndroidTmp` | 7 | 5 |
| `LanguageTable` | 7 | 5 |
| `Banc` | 6 | 2 |
| `KBCelNaznPlat` | 6 | 5 |
| `R_NewPer_Menu` | 6 | 4 |
| `R_S4et4ik` | 6 | 6 |
| `S_Type_Doc` | 6 | 5 |
| `sysdiagrams` | 6 | 5 |
| `R_Skidka` | 5 | 59 |
| `ZZ_Division` | 5 | 2 |
| `ZZ_Dolgnost` | 5 | 3 |
| `ZZ_Dolgnost_Regim` | 5 | 3 |
| `Access` | 4 | 3 |
| `R_NewPer_R_Ass` | 4 | 4 |
| `R_StolZona` | 4 | 3 |
| `R_Virt_Razd_Tree` | 4 | 3 |
| `Zagotovka` | 4 | 4 |
| `AdressSetting` | 3 | 8 |
| `R_Proizv2Type` | 3 | 4 |
| `R_Virt_Razd` | 3 | 6 |
| `Valuta` | 3 | 4 |
| `Adress` | 2 | 8 |
| `KBOrg` | 2 | 9 |
| `R_MenuLink` | 2 | 3 |
| `ZZ_Vid_Nayma` | 2 | 2 |
| `Account` | 1 | 2 |
| `Atr_Org` | 1 | 8 |
| `Net_AddStr` | 1 | 13 |
| `Other_BirtDay` | 1 | 10 |
| `R_ProizvDown` | 1 | 9 |
| `R_ProizvUp` | 1 | 23 |
| `S_Klient_Adress` | 1 | 6 |
| `S_Sklad` | 1 | 4 |
| `Sms` | 1 | 3 |
| `Z_Smena_Regim` | 1 | 2 |
| `Z_Smena_Regim_History` | 1 | 9 |
| `AkcizeAll` | 0 | 15 |
| `Birga` | 0 | 12 |
| `BirgaList` | 0 | 37 |
| `Connection` | 0 | 6 |
| `FOPRazd` | 0 | 4 |
| `FOPTerm` | 0 | 2 |
| `ForKA` | 0 | 5 |
| `ForKACloseStroka` | 0 | 23 |
| `ForKACloseStrokaTmp` | 0 | 21 |
| `ForKAListComp` | 0 | 3 |
| `ForKAListKA` | 0 | 13 |
| `ForKA_Akcize` | 0 | 6 |
| `ForKA_LogPrint` | 0 | 14 |
| `ForKA_Tmp` | 0 | 7 |
| `LanguageBirga` | 0 | 8 |
| `LanguageBirgaSostav` | 0 | 4 |
| `LanguageMenu` | 0 | 9 |
| `LanguageRazdel` | 0 | 5 |
| `Letter` | 0 | 5 |
| `Letter_File` | 0 | 8 |
| `Log_Synhr` | 0 | 5 |
| `Log_Web` | 0 | 3 |
| `Message` | 0 | 4 |
| `MyZagotovka` | 0 | 6 |
| `Net_AddNewZakaz` | 0 | 7 |
| `Net_Invayt` | 0 | 10 |
| `Net_InvaytLog` | 0 | 4 |
| `Net_Klent_Otziv` | 0 | 7 |
| `Net_Klent_OtzivSetting` | 0 | 9 |
| `Net_ListOrgRazvozka` | 0 | 3 |
| `Net_Log` | 0 | 8 |
| `Net_Message` | 0 | 7 |
| `Net_Oplata` | 0 | 8 |
| `Net_PhoneEnter` | 0 | 4 |
| `Net_PredStr` | 0 | 8 |
| `Net_PredUp` | 0 | 16 |
| `Net_WaitPhone` | 0 | 7 |
| `OP_Categorie` | 0 | 11 |
| `OP_Category_Descriptions` | 0 | 9 |
| `OP_Lang` | 0 | 9 |
| `OP_Length` | 0 | 7 |
| `OP_Manufacturer` | 0 | 6 |
| `OP_Product` | 0 | 35 |
| `OP_Product_Descriptions` | 0 | 10 |
| `OP_Product_Image` | 0 | 3 |
| `OP_Product_To_Category` | 0 | 2 |
| `OP_Stock` | 0 | 4 |
| `OP_Tax` | 0 | 9 |
| `OP_TaxClasses` | 0 | 7 |
| `OP_Weight` | 0 | 7 |
| `OplataVidSKB` | 0 | 4 |
| `Other_Phone` | 0 | 4 |
| `Per_Set_Sost` | 0 | 3 |
| `Privat24` | 0 | 7 |
| `R_Add_Info` | 0 | 7 |
| `R_Discont` | 0 | 3 |
| `R_Doc_S4et` | 0 | 4 |
| `R_Per2_List` | 0 | 5 |
| `R_Per2_Str` | 0 | 8 |
| `R_Per_Kompl` | 0 | 4 |
| `R_Per_Menu` | 0 | 4 |
| `R_Per_R_Ass` | 0 | 4 |
| `R_Podarok` | 0 | 4 |
| `R_Predv_Money` | 0 | 6 |
| `R_Print` | 0 | 8 |
| `R_Print_ErrKA` | 0 | 17 |
| `R_Print_RazdPodr` | 0 | 2 |
| `R_Proizv2Down` | 0 | 8 |
| `R_Proizv2Up` | 0 | 8 |
| `R_Proizv3Lenta` | 0 | 9 |
| `R_Proizv3Menu` | 0 | 4 |
| `R_Proizv3Type` | 0 | 2 |
| `R_ProizvMarshrut` | 0 | 8 |
| `R_ProizvOplata` | 0 | 9 |
| `R_ProizvTransport` | 0 | 3 |
| `R_St_AddBusy` | 0 | 3 |
| `R_Stol_Redirect` | 0 | 4 |
| `R_Virt_Razd_Dolgnost` | 0 | 6 |
| `R_WWW_S4et` | 0 | 5 |
| `R_WWW_S4etGroup` | 0 | 6 |
| `R_WWW_S4etOplataPar` | 0 | 5 |
| `R_WWW_S4etStroka` | 0 | 13 |
| `R_ZakupAndroid` | 0 | 8 |
| `S4et` | 0 | 15 |
| `S4etGeneration` | 0 | 3 |
| `S_AssortAkcize` | 0 | 5 |
| `S_KlientExport` | 0 | 20 |
| `S_KlientOplata` | 0 | 21 |
| `S_Klient_Bonus` | 0 | 6 |
| `S_Klient_RS` | 0 | 4 |
| `S_Klient_Sotr` | 0 | 5 |
| `S_Per` | 0 | 9 |
| `S_PerKomplekt` | 0 | 4 |
| `S_PerLog` | 0 | 7 |
| `S_PerMenu` | 0 | 4 |
| `S_PerPoluf` | 0 | 4 |
| `S_PerSpis` | 0 | 4 |
| `S_PerStr` | 0 | 7 |
| `S_Skidka` | 0 | 50 |
| `S_TreeView_NoVis_Komp` | 0 | 3 |
| `SettingPrinter` | 0 | 3 |
| `SmsAlfa` | 0 | 13 |
| `SmsKomu` | 0 | 3 |
| `Stat_IdZakaz` | 0 | 2 |
| `Temp_Numer` | 0 | 1 |
| `TmpS_Doc_Down` | 0 | 17 |
| `TmpS_Doc_Up` | 0 | 34 |
| `Vesi_Down` | 0 | 7 |
| `Vesi_Up` | 0 | 7 |
| `ZReport` | 0 | 3 |

## Foreign keys

Only 110 foreign keys are declared, so most relationships are by convention: a column named `X_Key` or `idX` points at the table that owns `X`. Treat the list below as a starting point, not as the full graph.

| From | Column | To | Column |
|---|---|---|---|
| `AccessPeople` | `KeyAccess` | `Access` | `KeyAccess` |
| `AccessPeople` | `No_People` | `Z_WorkMans` | `No_People` |
| `Birga` | `RazdelBirga` | `BirgaList` | `idBirga` |
| `FOPRazd` | `keyFOP` | `FOPTerm` | `keyFOP` |
| `FOPRazd` | `Key_Tree` | `S_TreeView` | `Key_Tree` |
| `ForKACloseOplata` | `Close_Key` | `ForKAClose` | `Close_Key` |
| `ForKACloseParam` | `Close_Key` | `ForKAClose` | `Close_Key` |
| `ForKACloseS4etStr` | `Close_Key` | `ForKAClose` | `Close_Key` |
| `ForKACloseStroka` | `Close_Key` | `ForKAClose` | `Close_Key` |
| `KBOper` | `Kod` | `KBCelNaznPlat` | `Kod` |
| `KBOper` | `Key_KBOrg` | `KBOrg` | `Key_KBOrg` |
| `KBOper` | `Kl_Key` | `S_Klient` | `Kl_Key` |
| `KBOrg` | `MFO` | `Banc` | `MFO` |
| `KBOrg` | `Key_Acc` | `Account` | `Key_Acc` |
| `KBOrg` | `Val_Key` | `Valuta` | `Val_Key` |
| `Letter_File` | `key_letter` | `Letter` | `key_letter` |
| `LinkTableLog` | `KeyUpTable` | `LinkTableUp` | `KeyUpTable` |
| `LinkTableStr` | `KeyUpTable` | `LinkTableUp` | `KeyUpTable` |
| `Net_PredStr` | `Key_NPUp` | `Net_PredUp` | `Key_NPUp` |
| `R_Akciya_Menu` | `Key_Akc` | `R_Akciya` | `Key_Akc` |
| `R_Assort_Koef` | `A_Key` | `S_Assort` | `A_Key` |
| `R_Assort_Koef` | `R_Ass_Key` | `R_Assort` | `R_Ass_Key` |
| `R_Doc_Down` | `idZakaz` | `R_Doc_Info` | `idZakaz` |
| `R_Doc_Down` | `Key_JobCreate` | `Z_WorkMansJob` | `Key_Job` |
| `R_Doc_Down` | `Mn_Key` | `R_Menu` | `Mn_Key` |
| `R_Doc_Down` | `St_Key` | `R_Stol` | `St_Key` |
| `R_Doc_Down` | `S_Key` | `S_Sklad` | `S_Key` |
| `R_Doc_Down` | `Kl_Key` | `S_Klient` | `Kl_Key` |
| `R_Kassa_Of` | `Dat` | `Z_Smena_History` | `Dat` |
| `R_Kassa_Of` | `Key_Job` | `Z_Smena_History` | `Key_Job` |
| `R_Kassa_Of` | `Kod` | `KBCelNaznPlat` | `Kod` |
| `R_Komplekt` | `Key_Tree` | `S_TreeView` | `Key_Tree` |
| `R_Komplekt_R_Assort` | `R_Ass_Key` | `R_Assort` | `R_Ass_Key` |
| `R_Komplekt_R_Assort` | `R_Kmpl_Key` | `R_Komplekt` | `R_Kmpl_Key` |
| `R_Menu` | `Key_Tree` | `S_TreeView` | `Key_Tree` |
| `R_Menu_Komplekt` | `Mn_Key` | `R_Menu` | `Mn_Key` |
| `R_Menu_Komplekt` | `R_Kmpl_Key` | `R_Komplekt` | `R_Kmpl_Key` |
| `R_NewPer` | `S_Key` | `S_Sklad` | `S_Key` |
| `R_NewPerStr` | `A_Key` | `S_Assort` | `A_Key` |
| `R_NewPerStr` | `R_Pereuchkey` | `R_NewPer` | `R_Pereuchkey` |
| `R_NewPer_Kompl` | `R_Pereuchkey` | `R_NewPer` | `R_Pereuchkey` |
| `R_NewPer_Menu` | `R_Pereuchkey` | `R_NewPer` | `R_Pereuchkey` |
| `R_NewPer_R_Ass` | `R_Pereuchkey` | `R_NewPer` | `R_Pereuchkey` |
| `R_Per_Kompl` | `S_Key` | `S_Sklad` | `S_Key` |
| `R_Per_Kompl` | `R_Kmpl_Key` | `R_Komplekt` | `R_Kmpl_Key` |
| `R_Per_Menu` | `S_Key` | `S_Sklad` | `S_Key` |
| `R_Per_Menu` | `Mn_Key` | `R_Menu` | `Mn_Key` |
| `R_Per_R_Ass` | `S_Key` | `S_Sklad` | `S_Key` |
| `R_Per_R_Ass` | `R_Ass_Key` | `R_Assort` | `R_Ass_Key` |
| `R_Predv` | `Kl_Key` | `S_Klient` | `Kl_Key` |
| `R_Predv` | `S_Key` | `S_Sklad` | `S_Key` |
| `R_Predv_Down` | `RDat_Key` | `R_Predv` | `RDat_Key` |
| `R_Predv_Down` | `Mn_Key` | `R_Menu` | `Mn_Key` |
| `R_Predv_Money` | `RDat_Key` | `R_Predv` | `RDat_Key` |
| `R_Predv_Money` | `Key_Job_Cr` | `Z_WorkMansJob` | `Key_Job` |
| `R_Predv_Stol` | `St_Key` | `R_Stol` | `St_Key` |
| `R_Predv_Stol` | `RDat_Key` | `R_Predv` | `RDat_Key` |
| `R_ProizvDown` | `R_Key_Nak` | `R_ProizvUp` | `R_Key_Nak` |
| `R_ProizvMarshrut` | `id_tran` | `R_ProizvTransport` | `id_tran` |
| `R_ProizvUp` | `Kl_Key` | `S_Klient` | `Kl_Key` |
| `R_S4et4ik` | `A_Key` | `S_Assort` | `A_Key` |
| `R_S4et4ik` | `S_Key` | `S_Sklad` | `S_Key` |
| `R_Stol` | `St_Type` | `R_Stol_Type` | `St_Type` |
| `R_Virt_Razd_Dolgnost` | `Virt_Key` | `R_Virt_Razd` | `Virt_Key` |
| `R_Virt_Razd_Dolgnost` | `Key_Dolgnost` | `ZZ_Dolgnost` | `Key_Dolgnost` |
| `R_Virt_Razd_Tree` | `Key_Tree` | `S_TreeView` | `Key_Tree` |
| `R_Virt_Razd_Tree` | `Virt_Key` | `R_Virt_Razd` | `Virt_Key` |
| `S_Assort` | `Key_Tree` | `S_TreeView` | `Key_Tree` |
| `S_Doc_Down` | `A_Key` | `S_Assort` | `A_Key` |
| `S_Doc_Down` | `DU_Key` | `S_Doc_Up` | `DU_Key` |
| `S_Doc_Up` | `Val_Key` | `Valuta` | `Val_Key` |
| `S_Doc_Up` | `Kl_Key` | `S_Klient` | `Kl_Key` |
| `S_Doc_Up` | `Key_Type` | `S_Type_Doc` | `Key_Type` |
| `S_Doc_Up` | `S_Key1` | `S_Sklad` | `S_Key` |
| `S_Doc_Up` | `S_Key2` | `S_Sklad` | `S_Key` |
| `S_KB_Oplata` | `DU_Key` | `S_Doc_Up` | `DU_Key` |
| `S_KB_Oplata` | `Key_Op` | `KBOper` | `Key_Op` |
| `S_Klient` | `Key_Tree` | `S_TreeView` | `Key_Tree` |
| `S_Klient_Adress` | `AdrKey` | `Adress` | `keyAtr` |
| `S_Klient_Adress` | `Kl_Key` | `S_Klient` | `Kl_Key` |
| `S_Klient_RS` | `Kl_Key` | `S_Klient` | `Kl_Key` |
| `S_Klient_RS` | `MFO` | `Banc` | `MFO` |
| `S_Klient_Sotr` | `Kl_Key` | `S_Klient` | `Kl_Key` |
| `S_O_TmpParam` | `ParamNum` | `S_O_Setting` | `ParamNum` |
| `S_Per` | `S_Key` | `S_Sklad` | `S_Key` |
| `S_PerKomplekt` | `S_PerKey` | `S_Per` | `S_PerKey` |
| `S_PerKomplekt` | `R_Kmpl_Key` | `R_Komplekt` | `R_Kmpl_Key` |
| `S_PerMenu` | `S_PerKey` | `S_Per` | `S_PerKey` |
| `S_PerMenu` | `Mn_Key` | `R_Menu` | `Mn_Key` |
| `S_PerPoluf` | `S_PerKey` | `S_Per` | `S_PerKey` |
| `S_PerPoluf` | `R_Ass_Key` | `R_Assort` | `R_Ass_Key` |
| `S_PerStr` | `S_PerKey` | `S_Per` | `S_PerKey` |
| `S_PerStr` | `A_Key` | `S_Assort` | `A_Key` |
| `S_Skl_Ass` | `S_Key` | `S_Sklad` | `S_Key` |
| `S_Skl_Ass` | `A_Key` | `S_Assort` | `A_Key` |
| `TmpS_Doc_Down` | `DU_Key_New` | `TmpS_Doc_Up` | `DU_Key_New` |
| `TmpS_Doc_Up` | `Kl_Key` | `S_Klient` | `Kl_Key` |
| `TmpS_Doc_Up` | `S_Key1` | `S_Sklad` | `S_Key` |
| `TmpS_Doc_Up` | `S_Key2` | `S_Sklad` | `S_Key` |
| `TmpS_Doc_Up` | `Key_Type` | `S_Type_Doc` | `Key_Type` |
| `ZZ_Dolgnost_Regim` | `Key_Dolgnost` | `ZZ_Dolgnost` | `Key_Dolgnost` |
| `ZZ_Dolgnost_Regim` | `Key_Regim` | `Z_Smena_Regim` | `Key_Regim` |
| `Z_Smena_History` | `Key_Reg_His` | `Z_Smena_Regim_History` | `Key_Reg_His` |
| `Z_Smena_History` | `Key_Job` | `Z_WorkMansJob` | `Key_Job` |
| `Z_Smena_Regim_History` | `Key_Regim` | `Z_Smena_Regim` | `Key_Regim` |
| `Z_WorkMans` | `Kl_Key` | `S_Klient` | `Kl_Key` |
| `Z_WorkMans` | `Key_Naym` | `ZZ_Vid_Nayma` | `Key_Naym` |
| `Z_WorkMans` | `Key_Div` | `ZZ_Division` | `Key_Div` |
| `Z_WorkMansJob` | `Key_Dolgnost` | `ZZ_Dolgnost` | `Key_Dolgnost` |
| `Z_WorkMansJob` | `No_People` | `Z_WorkMans` | `No_People` |

## Notes for the migration

- **`R_Doc_Down` is the heart of the system.** One row per ordered item, carrying its own state machine: `Fl_Prinyto` (accepted) → `Fl_Sdelano` (prepared) → `Fl_Vidano` (served) → `Fl_Oplacheno` (paid). Each flag has a matching timestamp (`D_Down_*`) and the staff member who set it (`Key_Job*`). Sofrexa keeps the same four stages, so this maps across directly.
- **Money is split across many columns** on the same row: `MyCena`, `MySkid` (discount), `MyObsl` (service charge), `MySumma`, `MyBonus`, `AddSkidka`. Recompute totals from the source rows during the import rather than trusting any single stored total.
- **This copy stops on 2025-11-24.** The final migration needs a fresh backup taken on the day of the switch-over; importing this one would lose everything after that date. Check the last order date of any new backup before importing it.
- **Deleted order lines live in `R_Doc_DownDel`**, not in `R_Doc_Down`. Import both — the void history is needed for the audit trail.
- **Text is almost all English.** The collation is Cyrillic because the program is Russian, but the Basilic data is not: 0 of 1,396 menu names, 3 of 960 customer names and 44 of 11,364 non-empty order-line notes contain Cyrillic. The name and note columns are `nvarchar`, so read them as Unicode and the collation does not matter for them. Only `varchar` columns need a CP1251 decode.
- **Dates use two forms:** real `datetime` columns, and an integer `Dat`/`Dat_Key` day number used for shift and day grouping. Map the integer form to a real date before importing.
- **Very few declared foreign keys.** Verify each assumed relationship against the data before relying on it, and expect orphan rows after six and a half years of use.
