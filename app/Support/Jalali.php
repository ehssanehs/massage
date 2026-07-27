<?php
namespace App\Support;

final class Jalali {
    public static function toJalali(?string $date): string {
        if (!$date) return '';
        $ts = strtotime($date); if (!$ts) return '';
        [$gy,$gm,$gd] = array_map('intval', explode('-', date('Y-m-d',$ts)));
        [$jy,$jm,$jd] = self::gregorianToJalali($gy,$gm,$gd);
        return self::fa(sprintf('%04d/%02d/%02d', $jy,$jm,$jd));
    }
    public static function toGregorian(?string $jalali): ?string {
        $jalali = trim(self::en((string)$jalali)); if ($jalali==='') return null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$jalali)) return $jalali;
        $parts = preg_split('#[/-]#',$jalali); if (count($parts)!==3) return null;
        [$jy,$jm,$jd] = array_map('intval',$parts); if ($jy > 1700) return sprintf('%04d-%02d-%02d',$jy,$jm,$jd);
        [$gy,$gm,$gd] = self::jalaliToGregorian($jy,$jm,$jd); return sprintf('%04d-%02d-%02d',$gy,$gm,$gd);
    }
    public static function fa(string|int|float|null $s): string { return strtr((string)$s, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']); }
    public static function en(string $s): string { return strtr($s, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']); }
    public static function gregorianToJalali($gy,$gm,$gd): array { $g_d_m=[0,31,59,90,120,151,181,212,243,273,304,334]; $gy2=($gm>2)?($gy+1):$gy; $days=355666+(365*$gy)+intdiv($gy2+3,4)-intdiv($gy2+99,100)+intdiv($gy2+399,400)+$gd+$g_d_m[$gm-1]; $jy=-1595+(33*intdiv($days,12053)); $days%=12053; $jy+=4*intdiv($days,1461); $days%=1461; if($days>365){$jy+=intdiv($days-1,365);$days=($days-1)%365;} if($days<186){$jm=1+intdiv($days,31);$jd=1+($days%31);}else{$jm=7+intdiv($days-186,30);$jd=1+(($days-186)%30);} return [$jy,$jm,$jd]; }
    public static function jalaliToGregorian($jy,$jm,$jd): array { $jy+=1595; $days=-355668+(365*$jy)+(intdiv($jy,33)*8)+intdiv(($jy%33)+3,4)+$jd+(($jm<7)?(($jm-1)*31):((($jm-7)*30)+186)); $gy=400*intdiv($days,146097); $days%=146097; if($days>36524){$gy+=100*intdiv(--$days,36524);$days%=36524;if($days>=365)$days++;} $gy+=4*intdiv($days,1461);$days%=1461;if($days>365){$gy+=intdiv($days-1,365);$days=($days-1)%365;} $gd=$days+1; $sal_a=[0,31,(($gy%4==0&&$gy%100!=0)||($gy%400==0))?29:28,31,30,31,30,31,31,30,31,30,31]; for($gm=1;$gm<=12&&$gd>$sal_a[$gm];$gm++)$gd-=$sal_a[$gm]; return [$gy,$gm,$gd]; }
}
